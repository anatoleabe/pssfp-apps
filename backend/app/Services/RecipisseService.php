<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Candidature;
use App\Support\CandidatureObjectStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Génère le PDF récépissé d'une candidature soumise (cf. spec module 5 §M8).
 *
 * Design institutionnel moderne (2 pages A4 exactement) :
 * - Page 1 « Copie candidat » : en-tête bilingue compact, bloc de confirmation
 *   (numéro de dossier proéminent + badge de statut), carte profil avec photo
 *   du candidat, choix académique, parcours, et QR de vérification en bas.
 * - Page 2 « Copie du Comité de Pilotage » : fiche d'analyse (profil,
 *   parcours, contrôle documentaire, décision, signature/cachet).
 * - Charte violet PSSFP (#4A2E67) sur cartes claires, lisible en niveaux de gris.
 * - QR code vers /v1/c/{uuid}/qr + code de vérification court (8 hex lisibles).
 *   Les identifiants techniques et le SHA-256 restent stockés côté serveur,
 *   mais ne sont jamais imprimés sur le document remis au candidat.
 *
 * Le PDF est stocké dans MinIO bucket `pssfp-candidatures` à la clé
 * `{uuid}/recipisse.pdf`. Téléchargement via URL signée 30 min (P-min-2 PR C).
 */
class RecipisseService
{
    private const SIGNED_URL_TTL_MINUTES = 30;

    /** Chemin disque relatif pour stocker le PDF dans le bucket pssfp-candidatures. */
    public function pathFor(Candidature $candidature): string
    {
        return "{$candidature->uuid}/recipisse.pdf";
    }

    /**
     * Génère le PDF, hash SHA256 du binaire, stocke dans MinIO.
     *
     * @return array{path: string, hash: string}
     */
    public function generate(Candidature $candidature): array
    {
        $candidature->loadMissing(['campagne', 'paysNationalite', 'regionRel', 'departementRel']);

        // Dates en français ("20 juillet 2026 à 20 h 47") côté template.
        Carbon::setLocale('fr');

        $qrUrl = url("/v1/c/{$candidature->uuid}/qr");
        $qrSvg = (string) QrCode::format('svg')->size(150)->margin(0)->generate($qrUrl);
        $qrSvgBase64 = 'data:image/svg+xml;base64,'.base64_encode($qrSvg);

        $logoBase64 = $this->embedImage(resource_path('images/pdf/logo.png'));
        $enteteBase64 = $this->embedImage(resource_path('images/pdf/entete.png'));
        $photoBase64 = $this->embedCandidatePhoto($candidature);

        $viewData = [
            'candidature' => $candidature,
            'campagne' => $candidature->campagne,
            'qrSvg' => $qrSvgBase64,
            'logoSrc' => $logoBase64,
            'enteteSrc' => $enteteBase64,
            'photoSrc' => $photoBase64,
            'generatedAt' => now(),
            'programName' => 'Master Professionnel en Finances Publiques',
            'contact' => [
                'adresse' => 'Campus de Messa, Yaoundé — Cameroun',
                'tel' => '+237 222 234 567',
                'web' => 'www.pssfp.org',
                'email' => 'contact@pssfp.org',
            ],
            // Placeholders remplacés après le 1er rendu (auto-référence hash).
            'hashPlaceholder' => '__HASH_PLACEHOLDER__',
            'vcodePlaceholder' => '__VCODE_PLACEHOLDER__',
        ];

        $html = view('pdf.candidature-recipisse', $viewData)->render();

        $pdfBytes = $this->renderPdf($html);

        // Hash SHA256 du binaire généré (cf. P-min-1 PR C). On regénère le PDF
        // une seconde fois en injectant le hash réel + un code de vérification
        // court (8 hex, lisible) dans les placeholders.
        $firstHash = hash('sha256', $pdfBytes);
        $vcode = strtoupper(substr($firstHash, 0, 4).'-'.substr($firstHash, 4, 4));
        $finalHtml = str_replace(
            ['__HASH_PLACEHOLDER__', '__VCODE_PLACEHOLDER__'],
            [$firstHash, $vcode],
            $html,
        );
        $finalBytes = $this->renderPdf($finalHtml);
        $finalHash = hash('sha256', $finalBytes);

        $path = $this->pathFor($candidature);

        // Les disques MinIO sont configurés `throw => false` : sans ce contrôle
        // un échec d'écriture serait invisible et la candidature référencerait
        // un récépissé inexistant.
        if ($this->disk()->put($path, $finalBytes) === false) {
            throw new RuntimeException("Écriture du récépissé impossible sur le stockage : {$path}");
        }

        return ['path' => $path, 'hash' => $finalHash];
    }

    /**
     * Régénère le récépissé d'un dossier déjà soumis et réaligne
     * `recipisse_pdf_path` / `recipisse_hash_sha256`.
     *
     * Sert au rattrapage : les récépissés produits avant la correction de
     * l'embarquement photo (403 HeadObject) sont sortis sans photographie et
     * doivent pouvoir être refaits depuis l'admin, sans toucher au statut ni
     * aux dates de transition du dossier.
     *
     * @throws RuntimeException si le dossier n'a jamais été soumis
     */
    public function regenerate(Candidature $candidature): string
    {
        if ($candidature->submitted_at === null) {
            throw new RuntimeException(
                "Le dossier {$candidature->numero_dossier} n'a pas été soumis : aucun récépissé à régénérer."
            );
        }

        $pdf = $this->generate($candidature);

        $candidature->update([
            'recipisse_pdf_path' => $pdf['path'],
            'recipisse_hash_sha256' => $pdf['hash'],
        ]);

        return $pdf['path'];
    }

    /**
     * URL signée pour téléchargement. Re-signée à chaque appel (cf. P-min-2 PR C).
     *
     * Retourne `null` si le driver ne supporte pas les URLs temporaires
     * (cas Storage::fake('local')).
     */
    public function signedUrl(string $path): string
    {
        $disk = $this->disk();

        if (method_exists($disk, 'temporaryUrl')) {
            return $disk->temporaryUrl($path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
        }

        // Fallback testing : disk local fake — on retourne juste le path
        // pour que les tests puissent assert sur la présence d'une chaîne stable.
        return 'fake://'.$path;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.default_candidatures_disk', 'minio_candidatures'));
    }

    /**
     * Rend le document et ajoute la pagination au centre du pied de page.
     * Le canvas Dompdf garantit un numéro exact sur chaque page sans dupliquer
     * de marqueur « Page X sur Y » dans le corps du document.
     */
    private function renderPdf(string $html): string
    {
        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('Source Sans 3', 'normal')
            ?: $fontMetrics->getFont('DejaVu Sans', 'normal');
        $fontSize = 7.1;
        $sample = 'Page 1 sur 2';
        $textWidth = $fontMetrics->getTextWidth($sample, $font, $fontSize);

        $canvas->page_text(
            ($canvas->get_width() - $textWidth) / 2,
            $canvas->get_height() - 25,
            'Page {PAGE_NUM} sur {PAGE_COUNT}',
            $font,
            $fontSize,
            [0.47, 0.44, 0.49],
        );

        return $pdf->output();
    }

    private function embedImage(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            return '';
        }

        $mime = match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($absolutePath));
    }

    /**
     * Lit la photo 4×4 du candidat sur le disque MinIO privé et l'embarque en
     * base64 pour l'afficher sur le récépissé. Retourne '' si aucune photo
     * (dépôt physique de la photo au bureau) ou si la lecture échoue —
     * le template dégrade alors sur un cadre « Photo » vide.
     *
     * Pas de pré-check `exists()` : la clé de service MinIO de production n'a
     * pas le droit `HeadObject` sur le bucket `pssfp-candidatures` et renvoie
     * 403, ce qui faisait échouer l'embarquement de TOUTES les photos alors
     * que `get()` (GetObject) passe sans problème. Un seul aller-retour suffit,
     * l'absence d'objet remonte de toute façon dans le catch.
     */
    private function embedCandidatePhoto(Candidature $candidature): string
    {
        $path = $candidature->photo_path;
        if ($path === null || $path === '') {
            return '';
        }

        try {
            $bytes = (string) Storage::disk(CandidatureObjectStorage::DISK)->get($path);
        } catch (\Throwable $e) {
            // Dégradation silencieuse côté document, mais jamais côté logs :
            // une photo absente du récépissé est un défaut visible pour le
            // candidat, il faut pouvoir la relier à une cause.
            Log::warning('recipisse_photo_embed_failed', [
                'candidature_uuid' => $candidature->uuid,
                'path' => $path,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return '';
        }

        if ($bytes === '') {
            Log::warning('recipisse_photo_empty', [
                'candidature_uuid' => $candidature->uuid,
                'path' => $path,
            ]);

            return '';
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'image/jpeg',
        };

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
