<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Candidature;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Génère le PDF récépissé d'une candidature soumise (cf. spec module 5 §M8).
 *
 * Design institutionnel moderne (2 pages A4 exactement) :
 * - Page 1 « Copie candidat » : en-tête bilingue compact, bloc de confirmation
 *   (numéro de dossier proéminent + badge de statut), carte profil avec photo
 *   du candidat, choix académique, parcours, et QR de vérification en bas.
 * - Page 2 « Copie administration » : fiche de contrôle (suivi du dossier,
 *   décision, signature/cachet) à l'usage interne de la scolarité.
 * - Charte violet PSSFP (#4A2E67) sur cartes claires, lisible en niveaux de gris.
 * - QR code vers /v1/c/{uuid}/qr + code de vérification court (8 hex lisibles).
 *   Le SHA-256 complet reste discret en pied de la page administration.
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

        $pdfBytes = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->output();

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
        $finalBytes = Pdf::loadHTML($finalHtml)
            ->setPaper('a4', 'portrait')
            ->output();
        $finalHash = hash('sha256', $finalBytes);

        $path = $this->pathFor($candidature);
        $this->disk()->put($path, $finalBytes);

        return ['path' => $path, 'hash' => $finalHash];
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
     */
    private function embedCandidatePhoto(Candidature $candidature): string
    {
        $path = $candidature->photo_path;
        if ($path === null || $path === '') {
            return '';
        }

        try {
            $disk = Storage::disk('minio_candidatures');
            if (! $disk->exists($path)) {
                return '';
            }
            $bytes = (string) $disk->get($path);
        } catch (\Throwable) {
            return '';
        }

        if ($bytes === '') {
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
