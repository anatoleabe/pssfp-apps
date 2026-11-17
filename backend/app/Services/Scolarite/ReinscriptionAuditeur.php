<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\AcmaDossier;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\Parcours;
use App\Models\User;
use App\Services\Acma\ReinscriptionAncien;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Réinscription, depuis sa fiche, d'un auditeur d'une promotion antérieure dans l'année en cours (17/11/2026).
 *
 * L'auditeur reste dans sa promotion : seule une inscription annuelle est créée, dans l'année en cours, au niveau
 * choisi (`InscriptionAnneeEnCours`). « Créer l'inscription » reste le geste de la promotion de l'année.
 *
 * Le M2 suppose un M1 déjà suivi (inscription M1 sur la plateforme, ou M1 dans l'archive ACMA liée) ; à défaut,
 * une dérogation motivée, avec la référence de sa pièce, par un titulaire du droit « dérogation M2 ».
 */
final class ReinscriptionAuditeur
{
    public const EVENEMENT = 'auditeur_reinscrit';

    public const DECLENCHEUR = 'reinscription';

    public const PIECE_MAXIMUM = 100;

    public function __construct(private readonly InscriptionAnneeEnCours $inscriptions) {}

    public static function peutReinscrire(?User $user): bool
    {
        return $user !== null && ($user->can(RegistreVersements::PERMISSION_SAISIR) || ConfirmationsVersements::peutConfirmer($user));
    }

    /** Auditeur d'une promotion antérieure, dossier actif, pas encore inscrit dans l'année en cours. */
    public static function possible(Auditeur $auditeur): bool
    {
        $annee = AnneeEnCours::annee();
        $dossier = $auditeur->candidature;
        if ($annee === null || $dossier === null || $dossier->withdrawn_at !== null || $dossier->campagne === null) {
            return false;
        }

        return AnneeEnCours::debut($dossier->campagne->anneeAcademique()) < AnneeEnCours::debut($annee)
            && $auditeur->inscriptionCourante?->annee_academique !== $annee;
    }

    /** M1 déjà suivi : inscription M1 sur la plateforme, ou M1 dans l'archive ACMA liée à cet auditeur. */
    public static function aSuiviLeM1(Auditeur $auditeur): bool
    {
        return InscriptionAnnuelle::query()->where('auditeur_id', $auditeur->id)->where('niveau', BaremeScolarite::NIVEAU_M1)->exists()
            || AcmaDossier::query()->where('auditeur_id', $auditeur->id)
                ->whereHas('inscriptions', fn ($q) => $q->whereNull('retire_de_la_source_at')->where('niveau', BaremeScolarite::NIVEAU_M1))
                ->exists();
    }

    /**
     * @param  array{niveau?: mixed, parcours?: mixed, derogation_m2?: mixed, derogation_piece?: mixed, motif?: mixed}  $saisie
     *
     * @throws InvalidArgumentException message destiné à l'agent ; rien n'est écrit
     */
    public function reinscrire(Auditeur $auditeur, array $saisie, User $auteur): InscriptionAnnuelle
    {
        abort_unless(self::peutReinscrire($auteur), 403);
        $auditeur->loadMissing(['candidature.campagne', 'inscriptionCourante']);
        $annee = AnneeEnCours::annee() ?? throw new InvalidArgumentException('Année en cours non réglée : aucune inscription possible.');
        if ($auditeur->candidature?->withdrawn_at !== null) {
            throw new InvalidArgumentException('Ce dossier est retiré : aucune inscription ne peut être créée.');
        }
        if (! self::possible($auditeur)) {
            throw new InvalidArgumentException("Réinscription réservée à un auditeur d'une promotion antérieure, pas encore inscrit en {$annee}.");
        }
        $niveau = (string) ($saisie['niveau'] ?? '');
        $derogation = $this->controlerNiveau($auditeur, $niveau, $saisie, $auteur);
        $parcours = Parcours::query()->where('code', (string) ($saisie['parcours'] ?? ''))->where('niveau', $niveau)->where('actif', true)->first()
            ?? throw new InvalidArgumentException('Parcours inconnu pour ce niveau.');

        return DB::transaction(function () use ($auditeur, $niveau, $parcours, $derogation, $auteur, $annee): InscriptionAnnuelle {
            $inscription = $this->inscriptions->inscrire($auditeur, $niveau, $parcours, $auteur, self::DECLENCHEUR);
            activity('scolarite')->causedBy($auteur)->performedOn($auditeur)
                ->event(self::EVENEMENT)
                ->withProperties(array_filter([
                    'annee' => $annee, 'niveau' => $niveau, 'parcours' => $parcours->code,
                    'promotion' => $auditeur->candidature?->campagne?->promotion_numero,
                    'inscription' => $inscription->id,
                    'derogation_m2' => $derogation !== null ? true : null,
                    'derogation_piece' => $derogation['piece'] ?? null,
                    'motif' => $derogation['motif'] ?? null,
                ], fn ($v): bool => $v !== null))
                ->log('Auditeur réinscrit dans l\'année en cours');

            return $inscription;
        });
    }

    /**
     * @param  array<string, mixed>  $saisie
     * @return array{piece: string, motif: string}|null la dérogation retenue, ou null
     */
    private function controlerNiveau(Auditeur $auditeur, string $niveau, array $saisie, User $auteur): ?array
    {
        if (! in_array($niveau, [BaremeScolarite::NIVEAU_M1, BaremeScolarite::NIVEAU_M2], true)) {
            throw new InvalidArgumentException('Niveau inconnu : M1 ou M2.');
        }
        if ($niveau === BaremeScolarite::NIVEAU_M1 || self::aSuiviLeM1($auditeur)) {
            return null;
        }
        if (! (bool) ($saisie['derogation_m2'] ?? false)) {
            throw new InvalidArgumentException('Aucun M1 suivi (plateforme ou archive ACMA liée) : le M2 demande une dérogation motivée, avec la référence de sa pièce.');
        }
        if (! $auteur->can(ReinscriptionAncien::PERMISSION_DEROGATION)) {
            throw new InvalidArgumentException('La dérogation au M1 demande le droit « dérogation M2 » (responsable de la scolarité).');
        }
        $piece = trim((string) ($saisie['derogation_piece'] ?? ''));
        if ($piece === '' || mb_strlen($piece) > self::PIECE_MAXIMUM) {
            throw new InvalidArgumentException('Indiquez la référence de la pièce de la dérogation (au plus '.self::PIECE_MAXIMUM.' caractères).');
        }

        return ['piece' => $piece, 'motif' => ReinscriptionAncien::motif((string) ($saisie['motif'] ?? ''))];
    }
}
