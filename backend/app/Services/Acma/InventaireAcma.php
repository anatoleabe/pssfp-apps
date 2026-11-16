<?php

declare(strict_types=1);

namespace App\Services\Acma;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lot 15a (spec du lot 15, §3 et §10.2) : comptes de l'export chargé dans le transit, sans aucune donnée nominative.
 * Les libellés ne portent que des codes de référentiel (années, niveaux, UE, phases, banques).
 *
 * Chaque section est une liste de lignes [libellé, valeur…] ; la première ligne d'une section tabulaire est l'en-tête.
 */
final class InventaireAcma
{
    private const SECTIONS_TABULAIRES = ['Années', 'Inscriptions par année', 'Paiements par année', 'Barèmes (montantAregler)', 'Programmes à crédits hors 60'];

    /** @return array<string, list<list<int|string>>> */
    public function calculer(): array
    {
        $chargement = LectureTransit::chargement();
        $etudiants = $this->etudiants();
        $inscriptions = $this->inscriptions();
        $paiements = $this->paiements($inscriptions['triplets']);
        $notes = $this->notes();
        $programmes = $this->programmes();

        $annees = [['Année', 'Statut ACMA', 'Inscriptions datées', 'Notes (année du programme)', 'Paiements']];
        foreach (LectureTransit::table('anneeacademic')->orderBy('anneeacademic')->get(['anneeacademic', 'debutannee']) as $a) {
            $code = (string) $a->anneeacademic;
            $annees[] = [$code, (string) $a->debutannee, $inscriptions['par_annee'][$code] ?? 0, $notes['par_annee'][$code] ?? 0, $paiements['par_annee'][$code]['lignes'] ?? 0];
        }

        $sections = [
            'Transit' => array_merge(
                [['Export', (string) ($chargement['manifeste']['source']['fichier'] ?? '—')], ['Date de l\'export', (string) ($chargement['manifeste']['source']['date_export'] ?? '—')]],
                array_map(fn (string $t): array => ["Lignes : {$t}", LectureTransit::table($t)->count()], ExtractionAcma::TABLES),
            ),
            'Années' => $annees,
            'Inscriptions' => $inscriptions['resume'],
            'Inscriptions par année' => $inscriptions['matrice'],
            'Notes' => $notes['resume'],
            'Programmes' => $programmes['resume'],
            'Programmes à crédits hors 60' => $programmes['credits'],
            'Paiements par année' => $paiements['matrice'],
            'Anomalies de paiement' => $paiements['anomalies'],
            'Barèmes (montantAregler)' => $this->baremes(),
            'Identités et matricules' => $etudiants,
            'Soutenances et documents' => $this->documents(),
            '2026-2027' => array_merge($inscriptions['en_cours'], $paiements['en_cours'], $notes['en_cours']),
        ];

        return $sections;
    }

    public static function tabulaire(string $section): bool
    {
        return in_array($section, self::SECTIONS_TABULAIRES, true);
    }

    /** @return list<list<int|string>> */
    private function etudiants(): array
    {
        $longueurs = [];
        $minuscules = $espacesBords = $espacesInternes = $autres = $zz = $sansDate = $dateAberrante = $vers = 0;
        $sexes = [];
        $normalises = [];
        foreach (LectureTransit::lignes('etudiant', ['matricule', 'sexe', 'datenaissance', 'typedatenais']) as $e) {
            $brut = (string) $e->matricule;
            $longueurs[mb_strlen($brut)] = ($longueurs[mb_strlen($brut)] ?? 0) + 1;
            $minuscules += (int) (preg_match('/[a-z]/', $brut) === 1);
            $espacesBords += (int) ($brut !== trim($brut));
            $espacesInternes += (int) (preg_match('/\S\s+\S/', $brut) === 1);
            $zz += (int) NormalisationAcma::estNeutralise($brut);
            $norme = NormalisationAcma::matricule($brut);
            $autres += (int) ($norme !== null && ! NormalisationAcma::estNeutralise($brut) && NormalisationAcma::aCaractereParasite($norme));
            if ($norme !== null) {
                $normalises[$norme] = ($normalises[$norme] ?? 0) + 1;
            }
            $sexe = strtoupper(trim((string) $e->sexe));
            $sexes[$sexe === '' ? 'vide' : $sexe] = ($sexes[$sexe === '' ? 'vide' : $sexe] ?? 0) + 1;
            if ((string) $e->typedatenais === '3') {
                $vers++;
            } elseif (trim((string) $e->datenaissance) === '') {
                $sansDate++;
            } elseif (! self::dateNaissanceValide((string) $e->datenaissance)) {
                $dateAberrante++;
            }
        }
        ksort($longueurs);
        ksort($sexes);
        $collisions = [];
        foreach (array_keys($normalises) as $m) {
            // Un matricule tout en chiffres devient une clé entière : on le relit en texte.
            $collisions['m'.NormalisationAcma::sansZerosDeTete((string) $m)][] = (string) $m;
        }
        $lignes = [['Fiches', array_sum($longueurs)]];
        foreach ($longueurs as $l => $n) {
            $lignes[] = ["Matricules de {$l} caractères", $n];
        }

        return array_merge($lignes, [
            ['Matricules avec minuscules', $minuscules],
            ['Matricules avec espaces autour', $espacesBords],
            ['Matricules avec espace interne', $espacesInternes],
            ['Matricules avec un autre caractère', $autres],
            ['Matricules préfixés zz', $zz],
            ['Matricules normalisés portés par plusieurs fiches', count(array_filter($normalises, fn (int $n): bool => $n > 1))],
            ['Collisions sans les zéros de tête (groupes)', count(array_filter($collisions, fn (array $g): bool => count($g) > 1))],
            ['Dates de naissance vides', $sansDate],
            ['Dates de naissance aberrantes', $dateAberrante],
            ['Dates « né vers »', $vers],
        ], array_map(fn (string $s, int $n): array => ["Sexe {$s}", $n], array_keys($sexes), $sexes));
    }

    /** @return array{resume: list<list<int|string>>, matrice: list<list<int|string>>, en_cours: list<list<int|string>>, par_annee: array<string, int>, triplets: array<string, true>} */
    private function inscriptions(): array
    {
        $total = $sansAnnee = $datees = 0;
        $auditeursSansAnnee = $triplets = $admisDistincts = $parAnnee = $matrice = $parAuditeur = $enCours = [];
        foreach (LectureTransit::lignes('inscription_academic', ['matricule', 'anneeacademic', 'niveau', 'admis']) as $i) {
            $total++;
            $m = NormalisationAcma::matricule($i->matricule) ?? '?';
            $annee = trim((string) $i->anneeacademic);
            $niveau = trim((string) $i->niveau);
            if ($annee === '' || $niveau === '') {
                $sansAnnee++;
                $auditeursSansAnnee[$m] = true;

                continue;
            }
            $datees++;
            $cle = "{$m}|{$annee}|{$niveau}";
            $nouveau = ! isset($triplets[$cle]);
            $triplets[$cle] = true;
            $parAnnee[$annee] = ($parAnnee[$annee] ?? 0) + 1;
            $n = CorrespondancesAcma::NIVEAUX[$niveau]['niveau'] ?? '?';
            $matrice[$annee][$n.'_inscrits'] = ($matrice[$annee][$n.'_inscrits'] ?? 0) + 1;
            if ((string) $i->admis === '1') {
                $matrice[$annee][$n.'_admis'] = ($matrice[$annee][$n.'_admis'] ?? 0) + 1;
                if ($annee <= CorrespondancesAcma::DERNIERE_ANNEE_REPRISE) {
                    $admisDistincts[$cle] = true;
                }
            }
            if ($nouveau) {
                $parAuditeur[$m][$annee][$niveau] = true;
            }
            if ($annee === CorrespondancesAcma::ANNEE_EN_COURS) {
                $prefixe = in_array(substr($m, 0, 2), ['26', '25'], true) ? substr($m, 0, 2) : 'autres';
                $enCours["Inscriptions 2026-2027, préfixe {$prefixe}, {$n}"] = ($enCours["Inscriptions 2026-2027, préfixe {$prefixe}, {$n}"] ?? 0) + 1;
            }
        }
        $reprises = count(array_filter(array_keys($triplets), fn (string $k): bool => explode('|', $k)[1] <= CorrespondancesAcma::DERNIERE_ANNEE_REPRISE));
        $deuxNiveaux = $redoublantsM1 = $plusieursM2 = 0;
        $parcours = ['1' => 0, '2' => 0, '3 à 6' => 0, 'plus de 6' => 0];
        foreach ($parAuditeur as $annees) {
            $nb = count($annees);
            $parcours[$nb === 1 ? '1' : ($nb === 2 ? '2' : ($nb <= 6 ? '3 à 6' : 'plus de 6'))]++;
            $m1 = $m2 = 0;
            foreach ($annees as $niveaux) {
                $deuxNiveaux += (int) (count($niveaux) > 1);
                $m1 += (int) isset($niveaux['FPTC4']);
                $m2 += (int) (count($niveaux) > (int) isset($niveaux['FPTC4']));
            }
            $redoublantsM1 += (int) ($m1 > 1);
            $plusieursM2 += (int) ($m2 > 1);
        }
        $fiches = [];
        foreach (LectureTransit::lignes('etudiant', ['matricule']) as $e) {
            $fiches[NormalisationAcma::matricule($e->matricule) ?? '?'] = true;
        }
        $sansInscription = count(array_diff_key($fiches, $parAuditeur));

        ksort($matrice);
        $tableau = [['Année', 'M1 inscrits', 'M1 admis', 'M2 inscrits', 'M2 admis']];
        foreach ($matrice as $annee => $c) {
            $tableau[] = [$annee, $c['M1_inscrits'] ?? 0, $c['M1_admis'] ?? 0, $c['M2_inscrits'] ?? 0, $c['M2_admis'] ?? 0];
        }
        ksort($enCours);

        return [
            'resume' => [
                ['Lignes d\'inscription', $total],
                ['Sans année ni niveau', $sansAnnee],
                ['Auditeurs concernés par ces lignes', count($auditeursSansAnnee)],
                ['Inscriptions datées', $datees],
                ['Inscriptions datées distinctes (matricule normalisé, année, niveau)', count($triplets)],
                ['Doublons', $datees - count($triplets)],
                ['Distinctes jusqu\'en 2025-2026', $reprises],
                ['Dont admis', count($admisDistincts)],
                ['Auditeurs-années à deux niveaux', $deuxNiveaux],
                ['Auditeurs inscrits une année', $parcours['1']],
                ['Auditeurs inscrits deux années', $parcours['2']],
                ['Auditeurs inscrits de trois à six années', $parcours['3 à 6']],
                ['Auditeurs inscrits plus de six années', $parcours['plus de 6']],
                ['Redoublants du M1 (deux inscriptions FPTC4 ou plus)', $redoublantsM1],
                ['Auditeurs inscrits en M2 deux années ou plus', $plusieursM2],
                ['Fiches sans inscription datée', $sansInscription],
            ],
            'matrice' => $tableau,
            'en_cours' => array_map(fn (string $k, int $v): array => [$k, $v], array_keys($enCours), $enCours),
            'par_annee' => $parAnnee,
            'triplets' => $triplets,
        ];
    }

    /** @return array{resume: list<list<int|string>>, en_cours: list<list<int|string>>, par_annee: array<string, int>} */
    private function notes(): array
    {
        $normeN = "upper(regexp_replace(coalesce(n.matricule, ''), '\\s', '', 'g'))";
        $nombres = [];
        foreach (['cc', 'tp', 'exam', 'ratra'] as $c) {
            $nombres[$c] = "(CASE WHEN n.{$c} ~ '^\\s*-?[0-9]+(\\.[0-9]+)?([eE][-+]?[0-9]+)?\\s*$' THEN n.{$c}::double precision END)";
        }
        $parAnnee = LectureTransit::table('notes as n')->leftJoin('acma_transit.programe as p', 'p.idprog', '=', 'n.idprog')
            ->groupBy('p.anneeacademic')->selectRaw("coalesce(p.anneeacademic, 'programme absent') AS annee, count(*) AS n")
            ->pluck('n', 'annee')->map(fn ($n): int => (int) $n)->all();
        $horsBornes = LectureTransit::table('notes as n')->where(function ($q) use ($nombres): void {
            foreach ($nombres as $x) {
                $q->orWhereRaw("{$x} < 0 OR {$x} > 20");
            }
        })->count();
        $couples = DB::query()->fromSub(
            LectureTransit::table('notes_hist as n')->selectRaw("DISTINCT n.idprog, {$normeN} AS m"), 'h'
        )->whereNotExists(fn ($q) => $q->from('acma_transit.notes as n')->whereColumn('n.idprog', 'h.idprog')->whereRaw("{$normeN} = h.m"));
        $avecProgramme = (clone $couples)->whereExists(fn ($q) => $q->from('acma_transit.programe as p')->whereColumn('p.idprog', 'h.idprog'))->count();
        $seulementHistorique = $couples->count();
        $grilles = LectureTransit::table('notes as n')->join('acma_transit.programe as p', 'p.idprog', '=', 'n.idprog')
            ->where('p.anneeacademic', CorrespondancesAcma::ANNEE_EN_COURS)->groupBy('n.codue')->orderBy('n.codue')
            ->selectRaw('n.codue AS ue, count(*) AS n')->pluck('n', 'ue');

        $total = array_sum($parAnnee);
        $reprises = array_sum(array_filter($parAnnee, fn (string $a): bool => CorrespondancesAcma::anneeReprise($a), ARRAY_FILTER_USE_KEY));

        return [
            'resume' => [
                ['Notes', $total],
                ['Notes jusqu\'en 2025-2026 (année du programme)', $reprises],
                ['Notes 2026-2027', $parAnnee[CorrespondancesAcma::ANNEE_EN_COURS] ?? 0],
                ['Notes dont le programme est absent', $parAnnee['programme absent'] ?? 0],
                ['Couples (programme, auditeur) seulement dans l\'historique', $seulementHistorique],
                ['… dont le programme existe (reconstituables)', $avecProgramme],
                ['… dont le programme a disparu', $seulementHistorique - $avecProgramme],
                ['Année de la note vide', LectureTransit::table('notes')->whereRaw("coalesce(trim(anneeacademic), '') = ''")->count()],
                ['Année de la note différente du programme', LectureTransit::table('notes as n')->join('acma_transit.programe as p', 'p.idprog', '=', 'n.idprog')
                    ->whereRaw("coalesce(trim(n.anneeacademic), '') <> ''")->whereColumn('n.anneeacademic', '<>', 'p.anneeacademic')->count()],
                ['Notes hors de 0 à 20', $horsBornes],
                ['Rattrapages saisis non appliqués (ok = 0, rattrapage > 0)', LectureTransit::table('notes as n')->whereRaw("coalesce(n.ok, '0') = '0'")->whereRaw("{$nombres['ratra']} > 0")->count()],
                ['Versions de notes (historique)', LectureTransit::table('notes_hist')->count()],
            ],
            'en_cours' => $grilles->map(fn ($n, $ue): array => ["Notes 2026-2027, UE {$ue}", (int) $n])->values()->all(),
            'par_annee' => $parAnnee,
        ];
    }

    /** @return array{resume: list<list<int|string>>, credits: list<list<int|string>>} */
    private function programmes(): array
    {
        $groupes = LectureTransit::table('grpue')->pluck('codniveau', 'codgrp')->all();
        $lignes = $horsNorme = $autreNiveau = 0;
        $credits = [];
        foreach (LectureTransit::lignes('programe', ['anneeacademic', 'codniveau', 'codgrp', 'pcc', 'ptp', 'pexam', 'credit']) as $p) {
            if (! CorrespondancesAcma::anneeReprise($p->anneeacademic)) {
                continue;
            }
            $lignes++;
            $somme = (float) LectureTransit::nombre($p->pcc) + (float) LectureTransit::nombre($p->ptp) + (float) LectureTransit::nombre($p->pexam);
            $horsNorme += (int) (abs($somme - 1) > 0.0001);
            $autreNiveau += (int) (isset($groupes[$p->codgrp]) && $groupes[$p->codgrp] !== $p->codniveau);
            $credits[$p->anneeacademic.' '.$p->codniveau] = ($credits[$p->anneeacademic.' '.$p->codniveau] ?? 0) + (int) LectureTransit::nombre($p->credit);
        }
        ksort($credits);
        $tableau = [['Année et niveau', 'Crédits']];
        foreach ($credits as $cle => $c) {
            if ($c !== 60) {
                $tableau[] = [$cle, $c];
            }
        }

        return [
            'resume' => [
                ['Lignes de programme jusqu\'en 2025-2026', $lignes],
                ['Pondérations dont la somme n\'est pas 1', $horsNorme],
                ['Lignes rattachées au groupe d\'un autre niveau', $autreNiveau],
            ],
            'credits' => $tableau,
        ];
    }

    /**
     * @param  array<string, true>  $triplets
     * @return array{matrice: list<list<int|string>>, anomalies: list<list<int|string>>, en_cours: list<list<int|string>>, par_annee: array<string, array{lignes: int}>}
     */
    private function paiements(array $triplets): array
    {
        $aujourdhui = CarbonImmutable::today()->format('Y-m-d');
        $parAnnee = $positifs = $negatifs = $doublons = $recus = $banques = $phases = $enCours = [];
        $somme = $nul = $futur = $ancien = $vide = $horsAnnee = $sansInscription = $decimales = 0;
        foreach (LectureTransit::lignes('du', ['IddroitU', 'matricule', 'Niveau', 'anneacademic', 'Montant', 'inscriptions', 'bibliotheque', 'fraisperiscol', 'tenuesport', 'dateRecu', 'NumeroRecu', 'NomBanque', 'codephase']) as $d) {
            $annee = trim((string) $d->anneacademic);
            $m = NormalisationAcma::matricule($d->matricule) ?? '?';
            $colonnes = array_map(fn ($v): float => (float) LectureTransit::nombre($v), [$d->Montant, $d->inscriptions, $d->bibliotheque, $d->fraisperiscol, $d->tenuesport]);
            foreach ($colonnes as $c) {
                $decimales += (int) (floor($c) !== $c);
            }
            $montant = (int) round($colonnes[0]);
            $total = (int) round(array_sum($colonnes));
            $parAnnee[$annee]['lignes'] = ($parAnnee[$annee]['lignes'] ?? 0) + 1;
            $parAnnee[$annee]['montant'] = ($parAnnee[$annee]['montant'] ?? 0) + $montant;
            $parAnnee[$annee]['total'] = ($parAnnee[$annee]['total'] ?? 0) + $total;
            if ($montant < 0) {
                $negatifs[] = [$m, $annee, $montant];
                $somme += $montant;
            } elseif ($montant > 0) {
                $positifs["{$m}|{$annee}|{$montant}"] = ($positifs["{$m}|{$annee}|{$montant}"] ?? 0) + 1;
            }
            $nul += (int) ($total === 0);
            $date = DatesAcma::date((string) $d->dateRecu);
            if ($date === null) {
                $vide++;
            } else {
                $futur += (int) ($date > $aujourdhui);
                $ancien += (int) ($date < '2013-01-01');
                $horsAnnee += (int) (NormalisationAcma::annee($annee) !== null && DatesAcma::horsAnnee($date, $annee));
            }
            $cle = implode('|', [$m, $annee, $montant, (string) $d->dateRecu, (string) $d->codephase]);
            $doublons[$cle] = ($doublons[$cle] ?? 0) + 1;
            $numero = trim((string) $d->NumeroRecu);
            if ($numero !== '') {
                $recus[$numero] = ($recus[$numero] ?? 0) + 1;
            }
            $sansInscription += (int) ! isset($triplets["{$m}|{$annee}|".trim((string) $d->Niveau)]);
            $banques[(string) $d->NomBanque] = ($banques[(string) $d->NomBanque] ?? 0) + 1;
            $phases[(string) $d->codephase] = ($phases[(string) $d->codephase] ?? 0) + 1;
            if ($annee === CorrespondancesAcma::ANNEE_EN_COURS) {
                $p = in_array(substr($m, 0, 2), ['26', '25'], true) ? substr($m, 0, 2) : 'autres';
                $enCours[$p]['lignes'] = ($enCours[$p]['lignes'] ?? 0) + 1;
                $enCours[$p]['auditeurs'][$m] = true;
                $enCours[$p]['montant'] = ($enCours[$p]['montant'] ?? 0) + $montant;
                $enCours[$p]['total'] = ($enCours[$p]['total'] ?? 0) + $total;
            }
        }
        $apparies = 0;
        foreach ($negatifs as [$m, $annee, $montant]) {
            $cle = "{$m}|{$annee}|".(-$montant);
            if (($positifs[$cle] ?? 0) > 0) {
                $positifs[$cle]--;
                $apparies++;
            }
        }
        ksort($parAnnee);
        $matrice = [['Année', 'Lignes', 'Colonne scolarité (Montant)', 'Total des 5 colonnes']];
        $cumul = ['reprises' => [0, 0, 0], 'toutes' => [0, 0, 0]];
        foreach ($parAnnee as $annee => $c) {
            $matrice[] = [(string) $annee, $c['lignes'], $c['montant'], $c['total']];
            foreach (CorrespondancesAcma::anneeReprise((string) $annee) ? ['reprises', 'toutes'] : ['toutes'] as $k) {
                $cumul[$k] = [$cumul[$k][0] + $c['lignes'], $cumul[$k][1] + $c['montant'], $cumul[$k][2] + $c['total']];
            }
        }
        $matrice[] = ['Jusqu\'en 2025-2026', ...$cumul['reprises']];
        $matrice[] = ['Total', ...$cumul['toutes']];
        $groupesDoublons = array_filter($doublons, fn (int $n): bool => $n > 1);
        $recusPartages = array_filter($recus, fn (int $n): bool => $n > 1);
        ksort($banques);
        ksort($phases);

        $enCoursLignes = [];
        foreach ($enCours as $p => $c) {
            $enCoursLignes[] = ["Paiements 2026-2027, préfixe {$p} : lignes", $c['lignes']];
            $enCoursLignes[] = ["Paiements 2026-2027, préfixe {$p} : auditeurs", count($c['auditeurs'])];
            $enCoursLignes[] = ["Paiements 2026-2027, préfixe {$p} : colonne scolarité", $c['montant']];
            $enCoursLignes[] = ["Paiements 2026-2027, préfixe {$p} : total des 5 colonnes", $c['total']];
        }

        return [
            'matrice' => $matrice,
            'anomalies' => array_merge([
                ['Lignes négatives (colonne scolarité)', count($negatifs)],
                ['Somme des lignes négatives', $somme],
                ['… avec contrepartie positive', $apparies],
                ['… isolées', count($negatifs) - $apparies],
                ['Lignes au total nul', $nul],
                ['Dates de reçu dans le futur', $futur],
                ['Dates de reçu avant 2013', $ancien],
                ['Dates de reçu vides ou invalides', $vide],
                ['Dates hors de l\'année académique à un an près', $horsAnnee],
                ['Groupes de doublons exacts', count($groupesDoublons)],
                ['Lignes en doublon exact', array_sum($groupesDoublons)],
                ['Numéros de reçu partagés', count($recusPartages)],
                ['Lignes à numéro de reçu partagé', array_sum($recusPartages)],
                ['Lignes sur un niveau sans inscription', $sansInscription],
                ['Valeurs décimales', $decimales],
            ], array_map(fn (string $b, int $n): array => ["Banque {$b}", $n], array_keys($banques), $banques),
                array_map(fn (string $p, int $n): array => ["Phase {$p}", $n], array_keys($phases), $phases)),
            'en_cours' => $enCoursLignes,
            'par_annee' => $parAnnee,
        ];
    }

    /** @return list<list<int|string>> */
    private function baremes(): array
    {
        $valeurs = [];
        foreach (LectureTransit::lignes('configurationdu', ['anneaacademic', 'niveau', 'montantAregler']) as $c) {
            $n = CorrespondancesAcma::NIVEAUX[(string) $c->niveau]['niveau'] ?? '?';
            $valeurs[(string) $c->anneaacademic][$n][(int) LectureTransit::nombre($c->montantAregler)] = true;
        }
        ksort($valeurs);
        $tableau = [['Année', 'M1', 'M2']];
        foreach ($valeurs as $annee => $v) {
            $tableau[] = [$annee, implode(' / ', array_keys($v['M1'] ?? [])), implode(' / ', array_keys($v['M2'] ?? []))];
        }

        return $tableau;
    }

    /** @return list<list<int|string>> */
    private function documents(): array
    {
        $plusieurs = LectureTransit::table('pvsoutenance')->selectRaw("upper(regexp_replace(coalesce(matricule, ''), '\\s', '', 'g')) AS m")
            ->groupByRaw("upper(regexp_replace(coalesce(matricule, ''), '\\s', '', 'g'))")->havingRaw('count(*) > 1')->get()->count();

        return [
            ['PV de soutenance', LectureTransit::table('pvsoutenance')->count()],
            ['Dossiers à plusieurs PV', $plusieurs],
            ['Certificats', LectureTransit::table('certificat')->count()],
            ['Attestations de réussite', LectureTransit::table('attestationr')->count()],
            ['Mises à disposition', LectureTransit::table('misedispos')->count()],
            ['Attestations de non-perception de bourse', LectureTransit::table('nonperceptionb')->count()],
            ['Lettres de stage', LectureTransit::table('prisecours')->count()],
        ];
    }

    private static function dateNaissanceValide(string $brut): bool
    {
        $date = DatesAcma::date($brut);

        return $date !== null && substr($date, 0, 4) >= '1940' && substr($date, 0, 4) <= '2010';
    }
}
