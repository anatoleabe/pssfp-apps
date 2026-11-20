<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\InscriptionAnnuelle;
use App\Models\SuspensionAuditeur;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\EchecEcampus;
use App\Services\Ecampus\SuspensionCompteEcampus;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Suspension d'un auditeur pour l'année en cours, sur sa demande écrite (spec module-4-scolarite-suspension,
 * décisions du 20/11/2026) :
 *
 * - réservée à l'administrateur (`scolarite.suspension`) ; la demande scannée est jointe au geste, et l'agent
 *   confirme l'avoir vérifiée ;
 * - l'auditeur passe « Suspendu », l'inscription de l'année est marquée : son reste n'est plus réclamé ;
 * - le compte eCampus est suspendu, puis rouvert à la levée. Une panne d'eCampus n'annule pas la suspension : le
 *   constat est gardé et le geste se refait depuis la fiche ;
 * - levée manuelle, avec un motif. Dans la même année, le reste redevient dû ; une année suivante, l'année suspendue
 *   reste non réclamée.
 */
final class SuspensionsAuditeurs
{
    public const PERMISSION = 'scolarite.suspension';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SuspensionCompteEcampus $ecampus,
    ) {}

    /**
     * @param  string  $demande  chemin du scan de la demande écrite, déposé par le formulaire
     *
     * @throws InvalidArgumentException message destiné à l'agent
     */
    public function suspendre(Auditeur $auditeur, string $demande, string $demandeDu, string $motif, string $effetLe, bool $verifiee, User $agent): SuspensionAuditeur
    {
        abort_unless($agent->can(self::PERMISSION), 403);

        $annee = AnneeEnCours::annee() ?? throw new InvalidArgumentException('Année en cours non réglée : aucune suspension possible.');
        if (! Versement::cheminJustificatifValide($demande) || Versement::pieceDejaJointe($demande)) {
            throw new InvalidArgumentException('Joignez le scan de la demande écrite de l\'auditeur.');
        }
        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Indiquez le motif de la demande.');
        }
        $lettre = self::date($demandeDu, 'la date de la demande');
        $effet = self::date($effetLe, 'la date d\'effet');
        if (! $verifiee) {
            throw new InvalidArgumentException('Cochez la confirmation : la demande est signée de l\'auditeur et vous l\'avez vérifiée.');
        }

        $suspension = $this->db->transaction(function () use ($auditeur, $annee, $demande, $lettre, $motif, $effet, $agent): SuspensionAuditeur {
            $verrouille = Auditeur::query()->whereKey($auditeur->id)->lockForUpdate()->firstOrFail();
            if ($verrouille->statut !== Auditeur::STATUT_ACTIF) {
                throw new InvalidArgumentException('L\'auditeur est « '.(Auditeur::STATUTS[$verrouille->statut] ?? $verrouille->statut).' » : seul un auditeur actif se suspend.');
            }
            $inscription = InscriptionAnnuelle::query()->where('auditeur_id', $verrouille->id)->where('annee_academique', $annee)
                ->lockForUpdate()->first()
                ?? throw new InvalidArgumentException('L\'auditeur n\'est pas inscrit en '.$annee.' : rien à suspendre cette année.');

            $suspension = new SuspensionAuditeur;
            $suspension->forceFill([
                'auditeur_id' => $verrouille->id,
                'inscription_annuelle_id' => $inscription->id,
                'annee_academique' => $annee,
                'demande_path' => $demande,
                'demande_du' => $lettre->toDateString(),
                'motif' => mb_substr($motif, 0, 500),
                'effet_le' => $effet->toDateString(),
                'suspendu_par' => $agent->id,
            ])->save();
            $inscription->forceFill(['suspendue_at' => now()])->save();
            $verrouille->forceFill([
                'statut' => Auditeur::STATUT_SUSPENDU,
                'statut_motif' => mb_substr('Suspendu pour '.$annee.' à sa demande (lettre du '.$lettre->format('d/m/Y').') : '.$motif, 0, 500),
                'statut_depuis' => $effet->toDateString(),
                'statut_par' => $agent->id,
            ])->save();

            activity('scolarite')->causedBy($agent)->performedOn($verrouille)->event('suspension')
                ->withProperties(['annee' => $annee, 'demande_du' => $lettre->toDateString(), 'effet_le' => $effet->toDateString(), 'motif' => $motif])
                ->log('Auditeur suspendu pour l\'année à sa demande écrite');

            return $suspension;
        });

        $this->synchroniserEcampus($suspension);
        $auditeur->refresh();

        return $suspension->refresh();
    }

    /** @throws InvalidArgumentException message destiné à l'agent */
    public function lever(Auditeur $auditeur, string $motif, User $agent): SuspensionAuditeur
    {
        abort_unless($agent->can(self::PERMISSION), 403);

        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Indiquez le motif de la levée.');
        }

        $suspension = $this->db->transaction(function () use ($auditeur, $motif, $agent): SuspensionAuditeur {
            $verrouille = Auditeur::query()->whereKey($auditeur->id)->lockForUpdate()->firstOrFail();
            $suspension = SuspensionAuditeur::query()->where('auditeur_id', $verrouille->id)->whereNull('levee_at')->lockForUpdate()->first()
                ?? throw new InvalidArgumentException('Aucune suspension en cours pour cet auditeur.');
            $memeAnnee = AnneeEnCours::annee() === $suspension->annee_academique;

            $suspension->forceFill([
                'levee_at' => now(),
                'levee_par' => $agent->id,
                'levee_motif' => mb_substr($motif, 0, 500),
                'reprise_meme_annee' => $memeAnnee,
            ])->save();
            if ($memeAnnee) {
                // Reprise dans l'année : le reste redevient dû.
                InscriptionAnnuelle::query()->whereKey($suspension->inscription_annuelle_id)->lockForUpdate()->firstOrFail()
                    ->forceFill(['suspendue_at' => null])->save();
            }
            $verrouille->forceFill([
                'statut' => Auditeur::STATUT_ACTIF,
                'statut_motif' => mb_substr('Suspension de '.$suspension->annee_academique.' levée : '.$motif, 0, 500),
                'statut_depuis' => InscriptionAnnuelle::aujourdhui()->toDateString(),
                'statut_par' => $agent->id,
            ])->save();

            activity('scolarite')->causedBy($agent)->performedOn($verrouille)->event('suspension_levee')
                ->withProperties(['annee' => $suspension->annee_academique, 'motif' => $motif, 'reprise_meme_annee' => $memeAnnee])
                ->log('Suspension de l\'auditeur levée');

            return $suspension;
        });

        $this->synchroniserEcampus($suspension);
        $auditeur->refresh();

        return $suspension->refresh();
    }

    /** Le compte eCampus n'est pas encore dans l'état voulu (suspendu, ou rouvert après la levée). */
    public static function ecampusARefaire(SuspensionAuditeur $s): bool
    {
        return $s->enCours() ? $s->ecampus_suspendu_at === null : $s->ecampus_rouvert_at === null;
    }

    /** Refait sur eCampus le geste qui a échoué (suspension ou réouverture). */
    public function reessayerEcampus(SuspensionAuditeur $suspension, User $agent): void
    {
        abort_unless($agent->can(self::PERMISSION), 403);
        if (! self::ecampusARefaire($suspension)) {
            throw new InvalidArgumentException('Le compte eCampus est déjà à jour.');
        }
        $this->synchroniserEcampus($suspension);
        $suspension->refresh();
        if (self::ecampusARefaire($suspension)) {
            throw new InvalidArgumentException((string) $suspension->ecampus_detail);
        }
    }

    /**
     * Met le compte eCampus dans l'état de la suspension. Une panne est gardée dans `ecampus_detail`, jamais levée :
     * la suspension (ou sa levée) est faite dans le portail.
     */
    private function synchroniserEcampus(SuspensionAuditeur $suspension): void
    {
        $candidature = $suspension->auditeur()->with('candidature')->first()?->candidature;
        $suspendre = $suspension->enCours();
        try {
            $detail = $candidature === null ? 'Dossier de candidature introuvable : aucun compte eCampus.' : $this->ecampus->appliquer($candidature, $suspendre);
            $suspension->forceFill([$suspendre ? 'ecampus_suspendu_at' : 'ecampus_rouvert_at' => now(), 'ecampus_detail' => mb_substr($detail, 0, 255)])->save();
        } catch (EchecEcampus $e) {
            $suspension->forceFill(['ecampus_detail' => mb_substr($e->getMessage(), 0, 255)])->save();
        }
    }

    private static function date(string $valeur, string $libelle): CarbonImmutable
    {
        if (blank($valeur)) {
            throw new InvalidArgumentException('Indiquez '.$libelle.'.');
        }
        $date = CarbonImmutable::parse($valeur, InscriptionAnnuelle::FUSEAU)->startOfDay();
        if ($date->greaterThan(InscriptionAnnuelle::aujourdhui())) {
            throw new InvalidArgumentException(ucfirst($libelle).' ne peut pas être dans le futur.');
        }

        return $date;
    }
}
