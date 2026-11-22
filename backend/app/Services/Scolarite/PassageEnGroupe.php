<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\Parcours;
use App\Models\User;
use App\Services\Lmd\ParcoursAuditeur;
use InvalidArgumentException;

/**
 * Passage en groupe du M1 au M2 (spec lot 18, règle 2) : les admis du M1 de l'année précédente, pas
 * encore inscrits dans l'année en cours, sont inscrits en M2 dans l'option de leur spécialité.
 *
 * Chaque inscription suit le chemin habituel (`InscriptionAnneeEnCours`) dans sa propre transaction :
 * l'échec de l'une n'annule pas les autres. Rejouable : un auditeur déjà inscrit est écarté.
 * L'avance du compte s'impute comme à une réinscription (même déclencheur) ; le journal porte le geste de groupe.
 */
final class PassageEnGroupe
{
    public const EVENEMENT = 'passage_m2_groupe';

    public function __construct(
        private readonly InscriptionAnneeEnCours $inscriptions,
        private readonly ParcoursAuditeur $parcours,
    ) {}

    public static function peutPasser(?User $user): bool
    {
        return ReinscriptionAuditeur::peutReinscrire($user);
    }

    /**
     * Qui passerait, et qui est écarté, sans rien écrire.
     *
     * @return array{annee: string, precedente: string, a_inscrire: list<array{auditeur: Auditeur, parcours: Parcours}>, sans_option: list<Auditeur>, retires: int, inactifs: int, deja_inscrits: int}
     *
     * @throws InvalidArgumentException année en cours non réglée
     */
    public function apercu(): array
    {
        $annee = AnneeEnCours::annee() ?? throw new InvalidArgumentException('Année en cours non réglée : aucun passage possible.');
        $precedente = AnneeEnCours::suivante($annee, -1);
        $bilan = ['annee' => $annee, 'precedente' => $precedente, 'a_inscrire' => [], 'sans_option' => [], 'retires' => 0, 'inactifs' => 0, 'deja_inscrits' => 0];

        $admis = ReinscriptionAuditeur::admisSurLaPlateforme($precedente)->with('auditeur.candidature')->orderBy('id')->get()
            ->pluck('auditeur')->filter()->unique('id');
        $dejaInscrits = InscriptionAnnuelle::query()->where('annee_academique', $annee)->whereIn('auditeur_id', $admis->pluck('id'))
            ->pluck('auditeur_id')->flip();

        foreach ($admis as $auditeur) {
            if ($dejaInscrits->has($auditeur->id)) {
                $bilan['deja_inscrits']++;
            } elseif ($auditeur->candidature === null || $auditeur->candidature->withdrawn_at !== null) {
                $bilan['retires']++;
            } elseif ($auditeur->statut !== Auditeur::STATUT_ACTIF) {
                $bilan['inactifs']++;
            } elseif (($parcours = $this->option($auditeur)) === null) {
                $bilan['sans_option'][] = $auditeur;
            } else {
                $bilan['a_inscrire'][] = ['auditeur' => $auditeur, 'parcours' => $parcours];
            }
        }

        return $bilan;
    }

    /**
     * Inscrit en M2 les admis de l'aperçu.
     *
     * @return array{inscrits: int, sans_option: int, echecs: list<string>}
     *
     * @throws InvalidArgumentException année en cours non réglée
     */
    public function executer(User $auteur): array
    {
        abort_unless(self::peutPasser($auteur), 403);
        $apercu = $this->apercu();
        $bilan = ['inscrits' => 0, 'sans_option' => count($apercu['sans_option']), 'echecs' => []];

        foreach ($apercu['a_inscrire'] as $ligne) {
            try {
                $this->inscriptions->inscrire($ligne['auditeur'], BaremeScolarite::NIVEAU_M2, $ligne['parcours'], $auteur, ReinscriptionAuditeur::DECLENCHEUR);
                $bilan['inscrits']++;
            } catch (InvalidArgumentException $e) {
                $bilan['echecs'][] = $ligne['auditeur']->candidature?->matricule.' : '.$e->getMessage();
            }
        }

        activity('scolarite')->causedBy($auteur)->event(self::EVENEMENT)
            ->withProperties(['annee' => $apercu['annee'], 'admis_de' => $apercu['precedente'], 'inscrits' => $bilan['inscrits'],
                'sans_option' => $bilan['sans_option'], 'echecs' => count($bilan['echecs'])])
            ->log('Passage en M2 des admis du M1');

        return $bilan;
    }

    /** Option de M2 de la spécialité du dossier, ou null si elle n'est pas reconnue. */
    private function option(Auditeur $auditeur): ?Parcours
    {
        $virtuelle = (new InscriptionAnnuelle)->forceFill(['niveau' => BaremeScolarite::NIVEAU_M2]);

        return $this->parcours->pour($virtuelle->setRelation('auditeur', $auditeur));
    }
}
