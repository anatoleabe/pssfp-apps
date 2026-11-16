<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\CorrectionVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\RecuVersement;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Vérification publique d'un reçu (reçu A4, R1) : le QR code de chaque exemplaire pointe vers une page qui n'affiche que le
 * minimum : numéro, date d'émission, montant, année, état (valide, ou annulé et remplacé), initiales du nom et matricule
 * partiellement masqué. Aucune autre donnée personnelle ; la page n'est pas indexée.
 */
final class VerificationRecus
{
    /** Taille du QR code, en points SVG (affiché à 20 mm dans le PDF). */
    private const TAILLE = 200;

    /** Marge blanche autour du code, en modules : la norme en demande 4 pour une lecture fiable. */
    private const MARGE = 4;

    public static function url(RecuVersement $recu): string
    {
        return rtrim((string) config('app.url'), '/').'/recus/verifier/'.$recu->uuid;
    }

    /** QR code SVG en data URI : DomPDF l'affiche sans imagick. */
    public static function qrCode(RecuVersement $recu): string
    {
        // Correction d'erreur de niveau M (15 %) : un exemplaire froissé ou tamponné se lit encore.
        $svg = (new Writer(new ImageRenderer(new RendererStyle(self::TAILLE, self::MARGE), new SvgImageBackEnd)))
            ->writeString(self::url($recu), Encoder::DEFAULT_BYTE_MODE_ECODING, ErrorCorrectionLevel::M());

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Ce que la page publique montre d'un reçu.
     *
     * @return array{numero: string, emis_le: string, montant: string, annee: string, titulaire: string, matricule: string, valide: bool, annule_le: ?string, remplace_par: ?string}
     */
    public static function fiche(RecuVersement $recu): array
    {
        $recu->loadMissing(['annulation', 'versement:id,auditeur_id', 'remplacant.versement:id,auditeur_id']);
        $remplacant = $recu->remplacant;
        $fuseau = InscriptionAnnuelle::FUSEAU;

        return [
            'numero' => $recu->numero,
            'emis_le' => $recu->emis_at->setTimezone($fuseau)->format('d/m/Y'),
            'montant' => RecusVersements::fcfa((int) $recu->montant),
            'annee' => (string) $recu->annee_academique,
            'titulaire' => self::initiales((string) $recu->auditeur_nom),
            'matricule' => self::masquer($recu->matricule),
            'valide' => $recu->annulation === null,
            'annule_le' => $recu->annulation?->annule_at?->setTimezone($fuseau)->format('d/m/Y'),
            // Jamais le reçu d'un autre auditeur (erreur d'auditeur corrigée) : seulement la pièce de correction.
            'remplace_par' => match (true) {
                $remplacant === null => null,
                $recu->memeAuditeur($remplacant) => 'le reçu '.$remplacant->numero,
                default => ($c = CorrectionVersement::query()->where('recu_remplacant_id', $remplacant->id)->value('numero')) !== null ? 'la correction '.$c : null,
            },
        ];
    }

    /** « ESSAI Auditeur Fictif » → « E. A. F. » */
    public static function initiales(string $nom): string
    {
        $mots = preg_split('/[\s\-]+/u', trim($nom), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(fn (string $m): string => mb_strtoupper(mb_substr($m, 0, 1)).'.', $mots));
    }

    /** « 26EFP999 » → « 26****99 » : les deux premiers et les deux derniers caractères seulement. */
    public static function masquer(?string $matricule): string
    {
        $m = (string) $matricule;
        $n = mb_strlen($m);

        return $n <= 4 ? str_repeat('*', $n) : mb_substr($m, 0, 2).str_repeat('*', $n - 4).mb_substr($m, -2);
    }
}
