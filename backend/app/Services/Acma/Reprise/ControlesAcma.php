<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\CorrespondancesAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\NormalisationAcma;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrôles d'équivalence (spec du lot 15, §10.5) : le transit (source) comparé à l'archive, une fois les exclusions
 * journalisées retirées. Les comptes de la source sont recalculés ici, sans réutiliser le code des étapes. Aucune donnée
 * nominative dans les résultats.
 */
final class ControlesAcma
{
    /** Modèle vivant qui ne doit pas bouger pendant la reprise (C09). */
    public const TABLES_VIVANTES = ['auditeurs', 'candidatures', 'users', 'inscriptions_annuelles', 'versements', 'imputations_versements', 'recus_versements', 'documents_delivres', 'acquis_ue', 'notes_ecue'];

    /** Files et traces d'envoi (C10). */
    public const TABLES_ENVOIS = ['jobs', 'failed_jobs', 'notifications', 'envois_messages'];

    /** @var array<string, true>|null */
    private ?array $retenus = null;

    /** @return list<ResultatControle> */
    public function executer(?ContexteReprise $contexte): array
    {
        $this->retenus = null;
        $resultats = [
            $this->c01(), $this->c02(), $this->c03(), ...$this->c04(), $this->c05(), $this->c08($contexte),
            $this->c09($contexte), $this->c10($contexte), $this->c11(),
        ];
        usort($resultats, fn (ResultatControle $a, ResultatControle $b): int => strcmp($a->code, $b->code));

        return $resultats;
    }

    /** @return array<string, int> */
    public static function compterVivant(): array
    {
        $comptes = [];
        foreach (array_merge(self::TABLES_VIVANTES, self::TABLES_ENVOIS) as $table) {
            $comptes[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        return $comptes;
    }

    /** Matricules normalisés des dossiers en place : les exclusions journalisées sont celles qui n'y sont pas. @return array<string, true> */
    private function retenus(): array
    {
        return $this->retenus ??= array_fill_keys(
            array_map(fn ($m): string => 'k'.$m, DB::table('acma_dossiers')->whereNull('retire_de_la_source_at')->pluck('matricule')->all()),
            true,
        );
    }

    private function retenu(?string $matricule): bool
    {
        return isset($this->retenus()['k'.NormalisationAcma::matricule($matricule)]);
    }

    private function c01(): ResultatControle
    {
        $zz = $test = $tous = [];
        foreach (LectureTransit::table('etudiant')->get(['matricule', 'parcours']) as $e) {
            $m = NormalisationAcma::matricule($e->matricule);
            if ($m === null) {
                continue;
            }
            $tous[$m] = true;
            if (str_starts_with($m, 'ZZ')) {
                $zz[$m] = true;
            }
            if (trim((string) $e->parcours) === CorrespondancesAcma::FILIERE_TEST) {
                $test[$m] = true;
            }
        }
        $reprises = $datees = $documents = [];
        foreach (LectureTransit::table('inscription_academic')->whereRaw("coalesce(trim(niveau), '') <> ''")->get(['matricule', 'anneeacademic']) as $i) {
            $m = (string) NormalisationAcma::matricule($i->matricule);
            if (NormalisationAcma::annee($i->anneeacademic) !== null) {
                $datees[$m] = true;
            }
            if (CorrespondancesAcma::anneeReprise($i->anneeacademic)) {
                $reprises[$m] = true;
            }
        }
        foreach (FichesAcma::DOCUMENTS as $table => $colonne) {
            foreach (LectureTransit::table($table)->pluck($colonne) as $m) {
                $documents[(string) NormalisationAcma::matricule($m)] = true;
            }
        }
        $attendu = 0;
        foreach (array_keys($tous) as $m) {
            $m = (string) $m;
            if (isset($zz[$m]) || isset($test[$m])) {
                continue;
            }
            $attendu += (int) (isset($reprises[$m]) || (! isset($datees[$m]) && isset($documents[$m])));
        }
        $obtenu = DB::table('acma_dossiers')->whereNull('retire_de_la_source_at')->count();
        $fusions = count($tous) < LectureTransit::table('etudiant')->count() ? LectureTransit::table('etudiant')->count() - count($tous) : 0;

        return new ResultatControle('C01', 'Dossiers : matricules normalisés distincts des fiches retenues', true, $attendu === $obtenu, (string) $attendu, (string) $obtenu,
            ["Fiches : {$this->nombre(LectureTransit::table('etudiant')->count())} ; fiches réunies par la normalisation : {$fusions} ; exclues (zz) : ".count($zz).' ; filière de test : '.count($test)]);
    }

    private function c02(): ResultatControle
    {
        [$attendu] = $this->inscriptionsSource();
        $obtenu = $this->parAnneeNiveau(DB::table('acma_inscriptions')->whereNull('retire_de_la_source_at'));

        return $this->comparaison('C02', 'Inscriptions par année et niveau (jusqu\'en 2025-2026)', true, $attendu, $obtenu);
    }

    private function c03(): ResultatControle
    {
        [, $attendu] = $this->inscriptionsSource();
        $obtenu = $this->parAnneeNiveau(DB::table('acma_inscriptions')->whereNull('retire_de_la_source_at')->where('admis_acma', true));

        return $this->comparaison('C03', 'Admis enregistrés par ACMA, par année et niveau', true, $attendu, $obtenu);
    }

    /** @return array{0: array<string, int>, 1: array<string, int>} inscrits et admis distincts de la source, par « année niveau » */
    private function inscriptionsSource(): array
    {
        $inscrits = $admis = [];
        foreach (LectureTransit::table('inscription_academic')->whereRaw("coalesce(trim(niveau), '') <> ''")->get(['matricule', 'anneeacademic', 'niveau', 'admis']) as $i) {
            if (! CorrespondancesAcma::anneeReprise($i->anneeacademic) || ! $this->retenu($i->matricule)) {
                continue;
            }
            $cle = trim((string) $i->anneeacademic).' '.trim((string) $i->niveau);
            $triplet = NormalisationAcma::matricule($i->matricule).'|'.$cle;
            $inscrits[$cle][$triplet] = true;
            if (trim((string) $i->admis) === '1') {
                $admis[$cle][$triplet] = true;
            }
        }

        return [array_map('count', $inscrits), array_map('count', $admis)];
    }

    /** @return list<ResultatControle> */
    private function c04(): array
    {
        $programmes = LectureTransit::table('programe')->pluck('anneeacademic', 'idprog')->all();
        $attendu = $couples = [];
        foreach (LectureTransit::lignes('notes', ['idprog', 'matricule'], 5000) as $n) {
            $couples[$n->idprog.'|'.NormalisationAcma::matricule($n->matricule)] = true;
            $annee = $programmes[$n->idprog] ?? null;
            if (CorrespondancesAcma::anneeReprise($annee) && $this->retenu($n->matricule)) {
                $attendu[$annee] = ($attendu[$annee] ?? 0) + 1;
            }
        }
        $reconstituables = [];
        foreach (LectureTransit::lignes('notes_hist', ['idprog', 'matricule'], 5000) as $h) {
            $couple = $h->idprog.'|'.NormalisationAcma::matricule($h->matricule);
            if (! isset($couples[$couple]) && CorrespondancesAcma::anneeReprise($programmes[$h->idprog] ?? null) && $this->retenu($h->matricule)) {
                $reconstituables[$couple] = true;
            }
        }
        $obtenu = DB::table('acma_resultats_ue as r')->join('acma_unites as u', 'u.id', '=', 'r.unite_id')
            ->whereNull('r.retire_de_la_source_at')->where('r.origine', 'notes')->groupBy('u.annee')
            ->selectRaw('u.annee, count(*) AS n')->pluck('n', 'annee')->map(fn ($n): int => (int) $n)->all();
        // Une note sans inscription de l'auditeur à l'année du programme est gardée parmi les non rattachées : reprise aussi.
        $sansInscription = DB::table('acma_notes_non_rattachees as r')->join('acma_unites as u', 'u.id_prog_acma', '=', 'r.id_prog_acma')
            ->whereNull('r.retire_de_la_source_at')->where('r.motif', 'sans_inscription')->groupBy('r.origine', 'u.annee')
            ->selectRaw('r.origine, u.annee, count(*) AS n')->get();
        $historiqueSansInscription = 0;
        foreach ($sansInscription as $s) {
            if ($s->origine === 'notes') {
                $obtenu[$s->annee] = ($obtenu[$s->annee] ?? 0) + (int) $s->n;
            } else {
                $historiqueSansInscription += (int) $s->n;
            }
        }
        $historique = DB::table('acma_resultats_ue')->whereNull('retire_de_la_source_at')->where('origine', 'historique')->count();
        $motifs = DB::table('acma_notes_non_rattachees')->whereNull('retire_de_la_source_at')->groupBy('motif')->selectRaw('motif, count(*) AS n')->pluck('n', 'motif');
        $conflits = DB::table('acma_resultats_ue')->whereNull('retire_de_la_source_at')->where('en_conflit', true)->count();

        return [
            $this->comparaison('C04a', 'Notes reprises par année (programme)', true, $attendu, $obtenu),
            new ResultatControle('C04b', 'Notes reconstituées depuis l\'historique', false, count($reconstituables) === $historique + $historiqueSansInscription, (string) count($reconstituables), (string) ($historique + $historiqueSansInscription),
                ["Rattachées à une inscription : {$historique} ; sans inscription de l'auditeur à l'année du programme (gardées non rattachées) : {$historiqueSansInscription}"]),
            new ResultatControle('C04c', 'Notes non rattachées et conflits', false, null, '—', ((int) ($motifs['programme_disparu'] ?? 0)).' programme disparu ; '.((int) ($motifs['sans_inscription'] ?? 0))." sans inscription ; {$conflits} résultats en conflit"),
        ];
    }

    private function c05(): ResultatControle
    {
        $details = [];
        $lignes = DB::table('acma_inscriptions')->whereNull('retire_de_la_source_at')
            ->selectRaw('annee, niveau_acma, count(*) AS n, count(*) FILTER (WHERE decision = \'non_enregistree\') AS sans_decision, '
                .'count(*) FILTER (WHERE signalements @> \'["admis_sans_toutes_les_ue"]\') AS admis_incomplets, '
                .'count(*) FILTER (WHERE signalements @> \'["credits_acma_differents"]\') AS credits_differents')
            ->groupBy('annee', 'niveau_acma')->orderBy('annee')->orderBy('niveau_acma')->get();
        foreach ($lignes as $l) {
            if ($l->sans_decision + $l->admis_incomplets + $l->credits_differents > 0) {
                $details[] = "{$l->annee} {$l->niveau_acma} : {$l->n} inscriptions, {$l->sans_decision} sans décision enregistrée, {$l->admis_incomplets} admis sans toutes les UE, {$l->credits_differents} crédits ACMA différents du recalcul";
            }
        }

        return new ResultatControle('C05', 'Crédits recalculés contre Totalcredit et contre la décision enregistrée', false, null, 'écarts connus (§3.3)', count($details).' cohortes avec écart', $details);
    }

    private function c08(?ContexteReprise $contexte): ResultatControle
    {
        $statuts = DB::table('acma_dossiers')->whereNull('retire_de_la_source_at')->groupBy('statut_rapprochement')
            ->selectRaw('statut_rapprochement AS s, count(*) AS n')->pluck('n', 's')->map(fn ($n): int => (int) $n)->all();
        $lies = ($statuts['lie_auto'] ?? 0) + ($statuts['lie_arbitre'] ?? 0);
        $infos = $contexte?->infos['rapprochement'] ?? null;
        $details = [
            'Liés automatiquement : '.($statuts['lie_auto'] ?? 0).' ; liés par arbitrage : '.($statuts['lie_arbitre'] ?? 0),
            'À arbitrer : '.($statuts['a_arbitrer'] ?? 0).' ; écartés : '.($statuts['ecarte'] ?? 0).' ; sans auditeur : '.($statuts['sans_auditeur'] ?? 0),
        ];
        if ($infos !== null) {
            $details[] = "Dans la plateforme, absents d'ACMA (R5) : {$infos['absents_acma']}";
            $details[] = "Matricules 25… de la plateforme : {$infos['plateforme_25']}, dont {$infos['plateforme_25_lies']} liés";
            $details[] = "Actifs dans ACMA en 2026-2027, absents de la plateforme : {$infos['actifs_hors_plateforme']}";
        }

        return new ResultatControle('C08', 'Rapprochement avec la plateforme', false, null, 'environ 295 liés (15/11)', "{$lies} liés", $details);
    }

    private function c09(?ContexteReprise $contexte): ResultatControle
    {
        return $this->avantApres('C09', 'Rien d\'autre n\'a changé (modèle vivant)', self::TABLES_VIVANTES, $contexte);
    }

    private function c10(?ContexteReprise $contexte): ResultatControle
    {
        $resultat = $this->avantApres('C10', 'Aucun envoi (files, notifications, messages, appels HTTP)', self::TABLES_ENVOIS, $contexte);
        if ($contexte === null || $contexte->requetesHttp === 0) {
            return $resultat;
        }

        return new ResultatControle('C10', $resultat->libelle, true, false, $resultat->attendu, $resultat->obtenu, [...$resultat->details, "Appels HTTP sortants : {$contexte->requetesHttp}"]);
    }

    /** @param  list<string>  $tables */
    private function avantApres(string $code, string $libelle, array $tables, ?ContexteReprise $contexte): ResultatControle
    {
        if ($contexte === null || $contexte->vivantAvant === []) {
            return new ResultatControle($code, $libelle, true, null, '0 écart', 'sans objet hors d\'une reprise');
        }
        $apres = self::compterVivant();
        $ecarts = [];
        foreach ($tables as $table) {
            if (($contexte->vivantAvant[$table] ?? 0) !== ($apres[$table] ?? 0)) {
                $ecarts[] = "{$table} : {$contexte->vivantAvant[$table]} avant, {$apres[$table]} après";
            }
        }

        return new ResultatControle($code, $libelle, true, $ecarts === [], '0 écart', count($ecarts).' écart(s)', $ecarts);
    }

    private function c11(): ResultatControle
    {
        $hors = fn (string $table, string $colonne): int => DB::table($table)->where(fn ($q) => $q->where($colonne, '>', CorrespondancesAcma::DERNIERE_ANNEE_REPRISE)->orWhere($colonne, '<', CorrespondancesAcma::PREMIERE_ANNEE))->count();
        $comptes = [
            'acma_annees' => $hors('acma_annees', 'code'),
            'acma_unites' => $hors('acma_unites', 'annee'),
            'acma_inscriptions' => $hors('acma_inscriptions', 'annee'),
            'acma_dossiers' => $hors('acma_dossiers', 'derniere_annee'),
        ];
        foreach (['acma_baremes' => 'annee', 'acma_paiements' => 'annee'] as $table => $colonne) {
            if (Schema::hasTable($table)) {
                $comptes[$table] = $hors($table, $colonne);
            }
        }
        $total = array_sum($comptes);

        return new ResultatControle('C11', 'Aucune donnée hors de 2013-2014 à 2025-2026 (2026-2027 non reprise)', true, $total === 0, '0', (string) $total,
            array_map(fn (string $t, int $n): string => "{$t} : {$n}", array_keys(array_filter($comptes)), array_filter($comptes)));
    }

    /** @return array<string, int> */
    private function parAnneeNiveau($requete): array
    {
        return $requete->groupBy('annee', 'niveau_acma')->selectRaw("annee || ' ' || niveau_acma AS cle, count(*) AS n")
            ->pluck('n', 'cle')->map(fn ($n): int => (int) $n)->all();
    }

    /**
     * @param  array<string, int>  $attendu
     * @param  array<string, int>  $obtenu
     */
    private function comparaison(string $code, string $libelle, bool $bloquant, array $attendu, array $obtenu): ResultatControle
    {
        $cles = array_unique(array_merge(array_keys($attendu), array_keys($obtenu)));
        sort($cles);
        $details = [];
        $ok = true;
        foreach ($cles as $cle) {
            $a = $attendu[$cle] ?? 0;
            $o = $obtenu[$cle] ?? 0;
            $ok = $ok && $a === $o;
            $details[] = "{$cle} : attendu {$this->nombre($a)}, obtenu {$this->nombre($o)}".($a === $o ? '' : ' ← écart');
        }

        return new ResultatControle($code, $libelle, $bloquant, $ok, $this->nombre(array_sum($attendu)), $this->nombre(array_sum($obtenu)), $details);
    }

    private function nombre(int|float $n): string
    {
        return number_format($n, 0, ',', ' ');
    }
}
