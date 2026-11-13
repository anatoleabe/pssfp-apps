<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Support\CandidatureObjectStorage;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Génère, pour l'administration, un fichier Word réunissant les documents
 * d'une promotion (une page par auditeur) : accès FOAD ou lettres d'admission.
 *
 * Seuls les retenus non retirés dotés d'un matricule y figurent, dans l'ordre
 * des matricules. La génération est refusée tant qu'un paramètre de la
 * campagne manque ; le scan de signature est facultatif. Chaque téléchargement est journalisé, sans
 * contenu (les accès FOAD contiennent des mots de passe).
 */
final class DocumentsAuditeurs
{
    public const PERMISSION_LETTRES = 'campagne.export_retenus';

    /** Lettre d'accès d'un auditeur soldé, un dossier à la fois (scolarité). */
    public const PERMISSION_LETTRE_ACCES_DOSSIER = 'scolarite.lettre_acces';

    public const DOSSIER_SIGNATURES = 'signatures';

    public function __construct(
        private readonly AccesFoadWord $acces,
        private readonly LettreAdmissionWord $admission,
        private readonly LettreAccesFoadPdf $accesPdf,
    ) {}

    /**
     * Lettre d'accès FOAD d'un auditeur, en PDF, depuis sa fiche : la seule
     * forme proposée sur la fiche (pas de Word modifiable, 13/11/2026), à
     * l'administration comme à la scolarité. Tracée sur la fiche.
     *
     * @return array{contenu: string, nom: string, mime: string}
     *
     * @throws DocumentImpossible droits non payés, ou paramètres des lettres absents
     */
    public function lettreAccesPdf(Candidature $c, User $auteur): array
    {
        abort_unless(self::autorise($auteur, ParametresLettres::DOCUMENT_ACCES, [(int) $c->id]), 403);
        if (! IdentifiantsMoodle::remettables($c)) {
            throw new DocumentImpossible('Les lettres d\'accès exigent les droits d\'inscription pointés.');
        }
        $contenu = $this->accesPdf->generer($c);

        activity('candidatures')->causedBy($auteur)->performedOn($c)
            ->event('acces_foad_exporte')
            ->log('Lettre d\'accès FOAD (PDF, avec mot de passe) téléchargée depuis la fiche');

        return ['contenu' => $contenu, 'nom' => 'acces-foad-'.mb_strtolower((string) $c->matricule).'.pdf', 'mime' => 'application/pdf'];
    }

    /**
     * @param  list<int>|null  $seulement  ids des dossiers choisis dans l'admin ; null = toute la promotion
     * @return array{contenu: string, nom: string, mime: string, pages: int}
     */
    public function generer(CampagneCandidature $campagne, string $document, User $auteur, ?array $seulement = null): array
    {
        abort_unless(self::autorise($auteur, $document, $seulement), 403);
        $parametres = ParametresLettres::pour($campagne);
        if (($manquants = $parametres->manquants($document)) !== []) {
            throw new DocumentsIncomplets($manquants);
        }

        $auditeurs = $this->auditeurs($campagne, $document, $seulement);
        if ($auditeurs->isEmpty()) {
            throw new DocumentImpossible(($document === ParametresLettres::DOCUMENT_ACCES ? 'Les lettres d\'accès exigent les droits d\'inscription pointés. ' : '').'Aucun auditeur concerné : les documents ne concernent que les retenus dotés d\'un matricule.');
        }

        $signature = $parametres->signature === null ? null : $this->signatureLocale($parametres->signature);
        try {
            $contenu = $document === ParametresLettres::DOCUMENT_ACCES
                ? $this->acces->generer($parametres, $auditeurs, $signature)
                : $this->admission->generer($parametres, $auditeurs, $signature);
        } finally {
            if ($signature !== null) {
                @unlink($signature);
            }
        }

        activity('candidatures')->causedBy($auteur)->performedOn($campagne)
            ->event('documents_auditeurs')
            ->withProperties(['document' => $document, 'pages' => $auditeurs->count(), 'selection' => $seulement !== null])
            ->log($document === ParametresLettres::DOCUMENT_ACCES ? 'Accès FOAD téléchargés (Word)' : 'Lettres d\'admission téléchargées (Word)');
        // Les accès contiennent des mots de passe : la fiche de chaque auditeur
        // garde la trace de qui les a exportés (jamais le mot de passe).
        if ($document === ParametresLettres::DOCUMENT_ACCES) {
            foreach ($auditeurs as $c) {
                activity('candidatures')->causedBy($auteur)->performedOn($c)
                    ->event('acces_foad_exporte')
                    ->log('Lettre d\'accès FOAD (avec mot de passe) incluse dans un téléchargement Word');
            }
        }

        return [
            'contenu' => $contenu,
            'nom' => self::nomFichier($campagne, $document, $auditeurs),
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pages' => $auditeurs->count(),
        ];
    }

    /**
     * Peut-il éditer ce document pour ces dossiers ? L'administration : tout.
     * La scolarité : la lettre d'accès d'un seul auditeur à la fois, depuis sa
     * fiche (demande du 13/11/2026) ; jamais un lot ni toute une promotion.
     *
     * @param  list<int>|null  $seulement
     */
    public static function autorise(User $u, string $document, ?array $seulement): bool
    {
        return $u->can(self::permission($document)) || self::parLaScolarite($u, $document, $seulement);
    }

    /** @param  list<int>|null  $seulement */
    public static function parLaScolarite(User $u, string $document, ?array $seulement): bool
    {
        return $document === ParametresLettres::DOCUMENT_ACCES
            && $seulement !== null && count($seulement) === 1
            && $u->can(self::PERMISSION_LETTRE_ACCES_DOSSIER);
    }

    public static function permission(string $document): string
    {
        return match ($document) {
            // Mots de passe : administration seule.
            ParametresLettres::DOCUMENT_ACCES => IdentifiantsMoodle::PERMISSION,
            ParametresLettres::DOCUMENT_ADMISSION => self::PERMISSION_LETTRES,
            default => throw new InvalidArgumentException("Document inconnu : {$document}"),
        };
    }

    /** @param  list<int>|null  $seulement */
    private function auditeurs(CampagneCandidature $campagne, string $document, ?array $seulement): Collection
    {
        return AttributionMatricules::retenus($campagne)
            ->whereNotNull('matricule')
            // La lettre d'accès contient le code : réservée aux inscriptions soldées.
            ->when($document === ParametresLettres::DOCUMENT_ACCES, fn ($q) => $q->whereNotNull('moodle_password')
                ->whereHas('auditeur.inscriptionCourante', fn ($i) => $i->inscriptionSoldee()))
            ->when($seulement !== null, fn ($q) => $q->whereIn('id', $seulement))
            ->orderBy('rang_retenu')
            ->get();
    }

    /**
     * Octets du scan de signature et cachet de la promotion.
     *
     * @throws DocumentsIncomplets chemin hors du dossier des signatures, ou fichier introuvable
     */
    public static function lireSignature(string $chemin): string
    {
        // Même bucket que les pièces des candidats : seul le dossier des signatures est lisible ici.
        if (! str_starts_with($chemin, self::DOSSIER_SIGNATURES.'/') || str_contains($chemin, '..')) {
            throw new DocumentsIncomplets(['Signature et cachet (fichier invalide : déposez-le à nouveau)']);
        }
        // Disque en `throw => false` : un objet absent revient vide, pas null.
        $contenu = CandidatureObjectStorage::read($chemin);
        if (blank($contenu)) {
            throw new DocumentsIncomplets(['Signature et cachet (fichier introuvable : déposez-le à nouveau)']);
        }

        return (string) $contenu;
    }

    /** PhpWord lit les images sur disque : copie temporaire de la signature privée. */
    private function signatureLocale(string $chemin): string
    {
        $contenu = self::lireSignature($chemin);
        $fichier = tempnam(sys_get_temp_dir(), 'pssfp-signature-');
        if ($fichier === false || file_put_contents($fichier, $contenu) === false) {
            throw new DocumentImpossible('Impossible de préparer l\'image de la signature.');
        }

        return $fichier;
    }

    /** « acces-foad-p14.docx », « lettres-admission-p14.docx » ; « lettre-admission-26efp007.docx » pour une seule personne. */
    private static function nomFichier(CampagneCandidature $campagne, string $document, Collection $auditeurs): string
    {
        $prefixe = $document === ParametresLettres::DOCUMENT_ACCES ? 'acces-foad' : 'lettre-admission';

        return $auditeurs->count() === 1
            ? $prefixe.'-'.mb_strtolower((string) $auditeurs->first()->matricule).'.docx'
            : ($document === ParametresLettres::DOCUMENT_ACCES ? 'acces-foad' : 'lettres-admission').'-p'.$campagne->promotion_numero.'.docx';
    }
}
