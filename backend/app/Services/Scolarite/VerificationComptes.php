<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrôles du compte de l'auditeur en lecture seule (spec D1, §10.2 et §10.4) : la migration
 * de reprise s'annule sur le moindre écart ; `scolarite:verifier-comptes` et le contrôle
 * interne CPT_COHERENCE les rejouent. Que des SELECT. Les écarts se nomment par uuid,
 * jamais par un nom.
 *
 * SQL brut autorisé par l'ADR-0013 (expressions de l'imputé et de l'avance) ; aucune valeur saisie.
 */
final class VerificationComptes
{
    /*
     * Ancienne source du cumul (lot 1), recopiée pour comparer les effectifs des filtres avant et
     * après la reprise : la somme des versements valides qui désignent l'inscription.
     */
    private const ANCIEN_VERSE = '(SELECT COALESCE(SUM(v.montant), 0) FROM versements v WHERE v.inscription_annuelle_id = inscriptions_annuelles.id AND v.annule_at IS NULL)';

    private const DU_TRANCHE2 = 'GREATEST(0, inscriptions_annuelles.montant_tranche2 - inscriptions_annuelles.remise_montant)';

    private const DU_TRANCHE1 = 'GREATEST(0, inscriptions_annuelles.montant_tranche1 - GREATEST(0, inscriptions_annuelles.remise_montant - inscriptions_annuelles.montant_tranche2))';

    private const DU_INSCRIPTION = 'GREATEST(0, inscriptions_annuelles.montant_inscription - GREATEST(0, inscriptions_annuelles.remise_montant - inscriptions_annuelles.montant_tranche1 - inscriptions_annuelles.montant_tranche2))';

    /** Versements imputés au-delà de leur montant, ou annulés avec des imputations actives. */
    public function versementsSurImputes(): Collection
    {
        return collect(DB::select(<<<'SQL'
            SELECT v.uuid, v.auditeur_id, v.montant, (v.annule_at IS NOT NULL) AS annule, SUM(x.montant) AS impute
            FROM versements v JOIN imputations_versements x ON x.versement_id = v.id AND x.annule_at IS NULL
            GROUP BY v.id
            HAVING SUM(x.montant) > CASE WHEN v.annule_at IS NULL THEN v.montant ELSE 0 END
            ORDER BY v.id
            SQL));
    }

    /** Inscriptions imputées au-delà de leur dû. */
    public function inscriptionsSurImputees(): Collection
    {
        return collect(DB::select(<<<'SQL'
            SELECT i.uuid, i.auditeur_id, (i.montant_inscription + i.montant_tranche1 + i.montant_tranche2 - i.remise_montant) AS du, SUM(x.montant) AS impute
            FROM inscriptions_annuelles i JOIN imputations_versements x ON x.inscription_annuelle_id = i.id AND x.annule_at IS NULL
            GROUP BY i.id
            HAVING SUM(x.montant) > i.montant_inscription + i.montant_tranche1 + i.montant_tranche2 - i.remise_montant
            ORDER BY i.id
            SQL));
    }

    /**
     * Invariant d'avance (§6.7) rompu : avance libre à côté d'un reste ouvert, ou avance réservée
     * alors que son inscription désignée a un reste. Signale un oubli de R3 dans le code.
     */
    public function avancesIncoherentes(): Collection
    {
        $reserves = implode(', ', array_fill(0, count(Versement::PAYEURS_RESERVES), '?'));

        return collect(DB::select(<<<SQL
            WITH dispo AS (
              SELECT v.id, v.auditeur_id, v.payeur IN ({$reserves}) AS reserve, v.inscription_annuelle_id,
                     v.montant - COALESCE((SELECT SUM(x.montant) FROM imputations_versements x WHERE x.versement_id = v.id AND x.annule_at IS NULL), 0) AS dispo
              FROM versements v WHERE v.annule_at IS NULL
            ), restes AS (
              SELECT i.id, i.auditeur_id, GREATEST(0, i.montant_inscription + i.montant_tranche1 + i.montant_tranche2 - i.remise_montant
                     - COALESCE((SELECT SUM(x.montant) FROM imputations_versements x WHERE x.inscription_annuelle_id = i.id AND x.annule_at IS NULL), 0)) AS reste
              FROM inscriptions_annuelles i
            ), comptes AS (
              SELECT a.id AS auditeur_id, a.uuid,
                (SELECT COALESCE(SUM(d.dispo), 0) FROM dispo d WHERE d.auditeur_id = a.id AND NOT d.reserve) AS avance_libre,
                (SELECT COALESCE(SUM(r.reste), 0) FROM restes r WHERE r.auditeur_id = a.id) AS restes,
                (SELECT COALESCE(SUM(d.dispo), 0) FROM dispo d JOIN restes r ON r.id = d.inscription_annuelle_id
                   WHERE d.auditeur_id = a.id AND d.reserve AND d.dispo > 0 AND r.reste > 0) AS reservee_en_attente
              FROM auditeurs a
            )
            SELECT * FROM comptes WHERE (avance_libre > 0 AND restes > 0) OR reservee_en_attente > 0 ORDER BY auditeur_id
            SQL, Versement::PAYEURS_RESERVES));
    }

    /**
     * Règlements d'organismes (lot A2) dont les versements valides ne font pas le montant, ou
     * qui, annulés, gardent un versement valide : un versement annulé seul, hors application.
     * Vide tant que la table n'existe pas (la reprise de D1 la précède).
     */
    public function reglementsIncoherents(): Collection
    {
        if (! Schema::hasTable('reglements_organismes')) {
            return collect();
        }

        return collect(DB::select(<<<'SQL'
            SELECT * FROM (
              SELECT r.uuid, r.reference, r.montant, (r.annule_at IS NOT NULL) AS annule,
                (SELECT COALESCE(SUM(v.montant), 0) FROM versements v WHERE v.reglement_organisme_id = r.id AND v.annule_at IS NULL) AS verse
              FROM reglements_organismes r
            ) c WHERE verse <> CASE WHEN annule THEN 0 ELSE montant END ORDER BY reference
            SQL));
    }

    /** Auditeurs dont les versements valides ne sont pas égaux à l'imputé plus l'avance. */
    public function comptesDesequilibres(): Collection
    {
        return collect(DB::select(<<<'SQL'
            SELECT * FROM (
              SELECT a.id AS auditeur_id, a.uuid,
                (SELECT COALESCE(SUM(v.montant), 0) FROM versements v WHERE v.auditeur_id = a.id AND v.annule_at IS NULL) AS verse,
                (SELECT COALESCE(SUM(x.montant), 0) FROM imputations_versements x JOIN inscriptions_annuelles i ON i.id = x.inscription_annuelle_id
                   WHERE i.auditeur_id = a.id AND x.annule_at IS NULL) AS impute,
                (SELECT COALESCE(SUM(v.montant - COALESCE((SELECT SUM(x.montant) FROM imputations_versements x WHERE x.versement_id = v.id AND x.annule_at IS NULL), 0)), 0)
                   FROM versements v WHERE v.auditeur_id = a.id AND v.annule_at IS NULL) AS avance
              FROM auditeurs a
            ) c WHERE verse <> impute + avance ORDER BY auditeur_id
            SQL));
    }

    /** @return array{versements: int, verse: int, impute: int, avance: int, avance_reservee: int, auditeurs_avec_avance: int} */
    public function totaux(): array
    {
        $reserves = implode(', ', array_fill(0, count(Versement::PAYEURS_RESERVES), '?'));
        $t = DB::selectOne(<<<SQL
            WITH dispo AS (
              SELECT v.auditeur_id, v.payeur IN ({$reserves}) AS reserve,
                     v.montant - COALESCE((SELECT SUM(x.montant) FROM imputations_versements x WHERE x.versement_id = v.id AND x.annule_at IS NULL), 0) AS dispo
              FROM versements v WHERE v.annule_at IS NULL
            )
            SELECT
              (SELECT count(*) FROM versements WHERE annule_at IS NULL) AS versements,
              (SELECT COALESCE(SUM(montant), 0) FROM versements WHERE annule_at IS NULL) AS verse,
              (SELECT COALESCE(SUM(montant), 0) FROM imputations_versements WHERE annule_at IS NULL) AS impute,
              (SELECT COALESCE(SUM(dispo), 0) FROM dispo) AS avance,
              (SELECT COALESCE(SUM(dispo), 0) FROM dispo WHERE reserve) AS avance_reservee,
              (SELECT count(DISTINCT auditeur_id) FROM dispo WHERE dispo > 0) AS auditeurs_avec_avance
            SQL, Versement::PAYEURS_RESERVES);

        return array_map(fn ($valeur): int => (int) $valeur, (array) $t);
    }

    /**
     * Écarts toujours valables (§10.4) : garde-fous refaits en SQL, conservation, invariant d'avance.
     *
     * @return list<string> clés `versement:<uuid>`, `inscription:<uuid>`, `auditeur:<uuid>`, `reglement:<uuid>`
     */
    public function ecarts(): array
    {
        return [
            ...$this->versementsSurImputes()->map(fn ($r): string => 'versement:'.$r->uuid)->all(),
            ...$this->inscriptionsSurImputees()->map(fn ($r): string => 'inscription:'.$r->uuid)->all(),
            ...$this->comptesDesequilibres()->map(fn ($r): string => 'auditeur:'.$r->uuid.' (versé ≠ imputé + avance)')->all(),
            ...$this->avancesIncoherentes()->map(fn ($r): string => 'auditeur:'.$r->uuid.' (avance à côté d\'un reste)')->all(),
            ...$this->reglementsIncoherents()->map(fn ($r): string => 'reglement:'.$r->uuid.' (versements ≠ montant du règlement)')->all(),
        ];
    }

    /**
     * Écarts d'équivalence avec l'ancien calcul (§10.2, contrôles 1 et 3, et sommes du 2) :
     * paliers et états identiques, avance égale à l'ancien trop-perçu, effectifs des filtres
     * identiques. Valables tant qu'aucune inscription n'a reçu l'argent d'un versement qui ne
     * la désigne pas : ces auditeurs-là sont exclus, et comptés.
     *
     * @return array{ecarts: list<string>, exclus: int, inscriptions: int}
     */
    public function equivalence(): array
    {
        $exclus = $this->auditeursCroises();
        $inscriptions = InscriptionAnnuelle::query()->whereNotIn('auditeur_id', $exclus)
            ->with('bareme')->withSum('versementsValides', 'montant')->withSum('imputationsActives', 'montant')
            ->orderBy('id')->get();
        $ecarts = [];
        $tropPercus = [];
        $aujourdhui = InscriptionAnnuelle::aujourdhui();
        foreach ($inscriptions as $i) {
            $ancien = (int) $i->versements_valides_sum_montant;
            $avant = $this->situation($i, $ancien, $aujourdhui);
            $apres = $this->situation($i, (int) $i->imputations_actives_sum_montant, $aujourdhui);
            if ($this->empreinte($avant) !== $this->empreinte($apres) || $apres->verse !== min($ancien, $avant->duTotal)) {
                $ecarts[] = 'inscription:'.$i->uuid.' (situation différente de l\'ancien calcul)';
            }
            $tropPercus[$i->auditeur_id] = ($tropPercus[$i->auditeur_id] ?? 0) + $avant->tropPercu;
        }

        foreach ($this->avancesParAuditeur($exclus) as $auditeurId => [$uuid, $avance]) {
            if (($tropPercus[$auditeurId] ?? 0) !== $avance) {
                $ecarts[] = 'auditeur:'.$uuid.' (avance '.$avance.' ≠ ancien trop-perçu '.($tropPercus[$auditeurId] ?? 0).')';
            }
        }

        foreach ($this->effectifs($exclus) as $filtre => [$avant, $apres]) {
            if ($avant !== $apres) {
                $ecarts[] = 'filtre:'.$filtre.' ('.$avant.' avant, '.$apres.' après)';
            }
        }

        return ['ecarts' => $ecarts, 'exclus' => count($exclus), 'inscriptions' => $inscriptions->count()];
    }

    /**
     * Effectifs des filtres de la liste, avec l'ancienne source du cumul puis la nouvelle. Les filtres
     * lisent ici tout l'argent imputé : la vérification porte sur le compte (D1), pas sur la
     * confirmation par la scolarité (15/11/2026).
     *
     * @param  list<int>  $exclus
     * @return array<string, array{0: int, 1: int}>
     */
    public function effectifs(array $exclus = []): array
    {
        $base = fn (): Builder => InscriptionAnnuelle::query()->whereNotIn('auditeur_id', $exclus);
        $jour = InscriptionAnnuelle::aujourdhui()->toDateString();
        $jusquTranche1 = self::DU_INSCRIPTION.' + '.self::DU_TRANCHE1;
        $ancienRetard = 'EXISTS (SELECT 1 FROM baremes_scolarite b WHERE b.id = inscriptions_annuelles.bareme_id AND ('
            .'(b.tranche1_echeance < ? AND '.self::DU_TRANCHE1.' > 0 AND '.self::ANCIEN_VERSE.' < '.$jusquTranche1.')'
            .' OR (b.tranche2_echeance < ? AND '.self::DU_TRANCHE2.' > 0 AND '.self::ANCIEN_VERSE.' < '.$jusquTranche1.' + '.self::DU_TRANCHE2.')'
            .'))';
        $ancienSoldee = self::ANCIEN_VERSE.' >= '.self::DU_INSCRIPTION;

        return [
            'inscription_soldee' => [$base()->whereRaw($ancienSoldee)->count(), $base()->inscriptionSoldee(true)->count()],
            'entierement_soldee' => [$base()->whereRaw(self::ANCIEN_VERSE.' >= '.$jusquTranche1.' + '.self::DU_TRANCHE2)->count(), $base()->entierementSoldee(true)->count()],
            'en_retard' => [$base()->whereRaw($ancienRetard, [$jour, $jour])->count(), $base()->enRetard(true)->count()],
            'acces_ouvert' => [
                $base()->where(fn (Builder $q) => $q->whereRaw($ancienSoldee)->orWhere(fn (Builder $d) => $d->whereHas('deblocageEnCours')
                    ->whereHas('auditeur', fn (Builder $a) => $a->whereNotIn('statut', MessagesAuditeurs::STATUTS_EXCLUS))))->count(),
                $base()->accesOuvert(true)->count(),
            ],
        ];
    }

    /** @return list<int> auditeurs dont une inscription a reçu l'argent d'un versement qui ne la désigne pas */
    public function auditeursCroises(): array
    {
        return array_map('intval', DB::table('imputations_versements as x')->join('versements as v', 'v.id', '=', 'x.versement_id')
            ->whereNull('x.annule_at')->whereColumn('v.inscription_annuelle_id', '<>', 'x.inscription_annuelle_id')
            ->distinct()->orderBy('x.auditeur_id')->pluck('x.auditeur_id')->all());
    }

    /**
     * @param  list<int>  $exclus
     * @return array<int, array{0: string, 1: int}> auditeur => [uuid, avance]
     */
    private function avancesParAuditeur(array $exclus): array
    {
        $lignes = DB::select(<<<'SQL'
            SELECT a.id, a.uuid,
              (SELECT COALESCE(SUM(v.montant - COALESCE((SELECT SUM(x.montant) FROM imputations_versements x WHERE x.versement_id = v.id AND x.annule_at IS NULL), 0)), 0)
                 FROM versements v WHERE v.auditeur_id = a.id AND v.annule_at IS NULL) AS avance
            FROM auditeurs a ORDER BY a.id
            SQL);
        $avances = [];
        foreach ($lignes as $l) {
            if (! in_array((int) $l->id, $exclus, true)) {
                $avances[(int) $l->id] = [(string) $l->uuid, (int) $l->avance];
            }
        }

        return $avances;
    }

    private function situation(InscriptionAnnuelle $i, int $verse, CarbonImmutable $aujourdhui): SituationFinanciere
    {
        return SituationFinanciere::calculer($i->montant_inscription, $i->montant_tranche1, $i->montant_tranche2, (int) $i->remise_montant,
            $verse, $aujourdhui, $i->bareme?->tranche1_echeance, $i->bareme?->tranche2_echeance);
    }

    /** Ce que l'équivalence compare : paliers, dû, reste, états. Ni le versé ni le trop-perçu, qui changent par construction. */
    private function empreinte(SituationFinanciere $s): string
    {
        return json_encode([
            array_map(fn (Palier $p): array => [$p->cle, $p->du, $p->paye, $p->reste, $p->enRetard], $s->paliers),
            $s->duTotal, $s->reste, $s->inscriptionSoldee(), $s->solde(), $s->enRetard(),
        ], JSON_THROW_ON_ERROR);
    }
}
