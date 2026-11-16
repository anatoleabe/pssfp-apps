<?php

declare(strict_types=1);

namespace App\Services\Acma;

use App\Models\AcmaDossier;
use App\Models\Candidature;
use App\Models\ReinscriptionAcma;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doublons d'un ancien qui se réinscrit (spec de la réinscription des anciens, §8.2) : la personne est-elle déjà sur
 * la plateforme (candidature, retenu, auditeur) sous un autre dossier ? Signaux : même date de naissance et noms
 * compatibles (seuil de `ComparaisonIdentite`), même téléphone, même e-mail.
 *
 * - déjà auditeur : refus, la conversion du matricule (15m) traitera le cas ;
 * - retenu sans matricule, ou candidature non décidée : refus, le jury et l'attribution passent d'abord ;
 * - candidature refusée : permis ;
 * - téléphone ou e-mail seuls : refus sans confirmation explicite (un numéro peut avoir changé de titulaire).
 */
final class DoublonsAncien
{
    /**
     * @param  array<string, mixed>  $saisie  `date_naissance`, `phone_e164`, `email`, `confirmer_signaux`
     *
     * @throws InvalidArgumentException message destiné à l'agent
     */
    public function refuser(AcmaDossier $dossier, array $saisie): void
    {
        $noms = [NormalisationAcma::jetons($dossier->nom_complet), NormalisationAcma::jetons((string) ($saisie['nom'] ?? ''), (string) ($saisie['prenom'] ?? ''))];
        $signauxSeuls = [];
        foreach ($this->candidats($saisie) as $c) {
            $jetons = NormalisationAcma::jetons($c->nom, $c->prenom, $c->epouse);
            $memeIdentite = $c->date_naissance?->toDateString() === $saisie['date_naissance']
                && collect($noms)->contains(fn (array $n): bool => ComparaisonIdentite::compatibles(ComparaisonIdentite::score($n, $jetons)));
            if ($memeIdentite) {
                $this->refuserMemePersonne($c);
            } elseif ($c->phone_e164 === $saisie['phone_e164'] || (filled($saisie['email'] ?? null) && $c->email === $saisie['email'])) {
                $signauxSeuls[] = $c->numero_dossier;
            }
        }
        if ($signauxSeuls !== [] && ! ($saisie['confirmer_signaux'] ?? false)) {
            throw new InvalidArgumentException('Ce téléphone ou cet e-mail figure déjà sur le dossier '.implode(', ', array_unique($signauxSeuls)).' d\'une autre identité : vérifiez-le, puis confirmez.');
        }
    }

    /**
     * Ancien déjà réinscrit depuis l'archive qui serait la même personne qu'un retenu du portail (§8.2, dernier cas) :
     * même date de naissance et noms compatibles. L'attribution du matricule de ce retenu est alors refusée.
     */
    public static function reinscritPour(Candidature $retenu): ?Candidature
    {
        if ($retenu->date_naissance === null) {
            return null;
        }
        $jetons = NormalisationAcma::jetons($retenu->nom, $retenu->prenom, $retenu->epouse);

        return Candidature::query()
            ->whereIn('id', ReinscriptionAcma::query()->where('mode', ReinscriptionAcma::CREATION)->select('candidature_id'))
            ->whereKeyNot($retenu->id)
            ->whereDate('date_naissance', $retenu->date_naissance->toDateString())
            ->get(['id', 'numero_dossier', 'matricule', 'nom', 'prenom', 'epouse'])
            ->first(fn (Candidature $c): bool => ComparaisonIdentite::compatibles(ComparaisonIdentite::score($jetons, NormalisationAcma::jetons($c->nom, $c->prenom, $c->epouse))));
    }

    /**
     * Dossier d'archive non lié (ni écarté, ni retiré, ni réinscrit, ni arbitré « personnes différentes ») à la même
     * date de naissance et aux noms compatibles qu'un retenu sans matricule : probablement un ancien auditeur, dont le
     * matricule ACMA fait foi (Q2).
     */
    public static function dossierArchivePour(Candidature $retenu): ?AcmaDossier
    {
        if ($retenu->date_naissance === null) {
            return null;
        }
        $date = $retenu->date_naissance->toDateString();
        $jetons = NormalisationAcma::jetons($retenu->nom, $retenu->prenom, $retenu->epouse);

        return AcmaDossier::query()
            ->whereIn('statut_rapprochement', ['sans_auditeur', 'a_arbitrer'])
            ->whereNull('retire_de_la_source_at')
            ->whereNotIn('id', ReinscriptionAcma::query()->select('dossier_acma_id'))
            ->whereNotIn('id', DB::table('acma_rapprochements')->where('decision', 'non_lie')->whereNotNull('decide_par')->select('dossier_id'))
            ->where(fn ($q) => $q->whereDate('date_naissance', $date)
                ->orWhereIn('id', DB::table('acma_complements')->where('champ', 'date_naissance')->where('valeur', $date)->select('dossier_id')))
            ->get(['id', 'uuid', 'matricule', 'nom_complet'])
            ->first(fn (AcmaDossier $d): bool => ComparaisonIdentite::compatibles(ComparaisonIdentite::score(NormalisationAcma::jetons($d->nom_complet), $jetons)));
    }

    private function refuserMemePersonne(Candidature $c): void
    {
        if ($c->withdrawn_at !== null || $c->trashed()) {
            return;
        }
        if ($c->auditeur !== null) {
            throw new InvalidArgumentException("Cette personne est déjà auditeur de la plateforme (dossier {$c->numero_dossier}, matricule {$c->matricule}) : le dossier ACMA fait foi, reprenez le matricule ACMA sur ce dossier au lieu d'en créer un second.");
        }
        if ($c->statut === Candidature::STATUT_ACCEPTE) {
            throw new InvalidArgumentException("Cette personne est retenue sur le dossier {$c->numero_dossier}, sans matricule encore : reprenez son matricule ACMA sur ce dossier (« Reprendre le matricule ACMA »), sans second dossier.");
        }
        if ($c->statut !== Candidature::STATUT_REFUSE && $c->submitted_at !== null) {
            throw new InvalidArgumentException("Cette personne a une candidature en cours (dossier {$c->numero_dossier}) : attendez la décision du jury.");
        }
    }

    /**
     * Candidatures (toutes campagnes, supprimées comprises) à la même date de naissance, au même téléphone ou au même e-mail.
     *
     * @param  array<string, mixed>  $saisie
     * @return iterable<Candidature>
     */
    private function candidats(array $saisie): iterable
    {
        return Candidature::withTrashed()->with('auditeur')
            ->where(function ($q) use ($saisie): void {
                $q->whereDate('date_naissance', (string) $saisie['date_naissance'])
                    ->orWhere('phone_e164', (string) $saisie['phone_e164'])
                    ->when(filled($saisie['email'] ?? null), fn ($w) => $w->orWhere('email', (string) $saisie['email']));
            })
            ->orderBy('id')->get();
    }
}
