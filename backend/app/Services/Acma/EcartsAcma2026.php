<?php

declare(strict_types=1);

namespace App\Services\Acma;

use App\Models\User;
use App\Services\Acma\Reprise\RapportsReprise;
use App\Support\Journaux;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Rapport des écarts 2026-2027 entre ACMA et la plateforme (spec du lot 15, §9 ; décision 2 : la plateforme fait foi).
 * Lecture seule sur le transit et sur la plateforme : rien n'est importé ni modifié. Le classeur, nominatif, va dans le
 * stockage privé ; la console et le journal ne reçoivent que des comptes.
 *
 * Rapprochement des paiements, auditeur par auditeur : même référence normalisée (reçu ACMA contre référence du
 * versement) ; sinon même total à 7 jours près ; sinon somme des lignes ACMA d'un même reçu contre un versement.
 */
final class EcartsAcma2026
{
    private const JOURS = 7;

    private const PROMOTIONS = ['P14', 'P13', 'P12 et antérieures'];

    /** @var array<string, object> */
    private array $plateforme = [];

    /** @var array<string, string> */
    private array $nomsAcma = [];

    /** @var array<string, string> */
    private array $premiereAnneeAcma = [];

    /** @return array{chemin: string, synthese: list<array<string, int|string>>, comptes: array<string, int>} */
    public function produire(User $auteur, ?string $sortie = null): array
    {
        LectureTransit::chargement();
        $this->charger();
        $annee = CorrespondancesAcma::ANNEE_EN_COURS;

        $acma = [];
        foreach (LectureTransit::table('du')->where('anneacademic', $annee)->orderBy('_ligne')->get(['IddroitU', 'matricule', 'Niveau', 'Montant', 'inscriptions', 'bibliotheque', 'fraisperiscol', 'tenuesport', 'dateRecu', 'NumeroRecu', 'codephase']) as $d) {
            $montant = (int) round((float) LectureTransit::nombre($d->Montant));
            $total = 0;
            foreach ([$d->Montant, $d->inscriptions, $d->bibliotheque, $d->fraisperiscol, $d->tenuesport] as $v) {
                $total += (int) round((float) LectureTransit::nombre($v));
            }
            $m = (string) NormalisationAcma::matricule($d->matricule);
            $acma[(int) $d->IddroitU] = (object) [
                'id' => (int) $d->IddroitU, 'matricule' => $m, 'niveau' => trim((string) $d->Niveau), 'montant' => $montant, 'total' => $total,
                'date' => DatesAcma::date($d->dateRecu), 'recu' => trim((string) $d->NumeroRecu), 'reference' => self::reference($d->NumeroRecu),
                'phase' => (string) $d->codephase, 'promotion' => $this->promotion($m),
            ];
        }
        $versements = [];
        foreach (DB::table('versements as v')->join('inscriptions_annuelles as i', 'i.id', '=', 'v.inscription_annuelle_id')
            ->join('auditeurs as a', 'a.id', '=', 'v.auditeur_id')->join('candidatures as c', 'c.id', '=', 'a.candidature_id')
            ->where('i.annee_academique', $annee)->whereNull('v.annule_at')->orderBy('v.id')
            ->get(['v.id', 'v.montant', 'v.date_versement', 'v.reference', 'v.mode', 'c.matricule']) as $v) {
            $m = (string) NormalisationAcma::matricule($v->matricule);
            $versements[(int) $v->id] = (object) [
                'id' => (int) $v->id, 'matricule' => $m, 'montant' => (int) $v->montant, 'date' => substr((string) $v->date_versement, 0, 10),
                'reference' => self::reference($v->reference), 'reference_brute' => (string) $v->reference, 'mode' => (string) $v->mode, 'promotion' => $this->promotion($m),
            ];
        }

        [$apparies, $differents] = $this->rapprocher($acma, $versements);
        $absentsPlateforme = array_values(array_filter($acma, fn (object $l): bool => ! isset($apparies['a'.$l->id]) && isset($this->plateforme['k'.$l->matricule])));
        $horsPlateforme = array_values(array_filter($acma, fn (object $l): bool => ! isset($this->plateforme['k'.$l->matricule])));
        $absentsAcma = array_values(array_filter($versements, fn (object $v): bool => ! isset($apparies['v'.$v->id])));

        $feuilles = [
            'Synthèse' => $this->synthese($acma, $versements),
            'Absents de la plateforme' => array_map(fn (object $l): array => $this->ligneAcma($l), $absentsPlateforme),
            'Montants différents' => $differents,
            'Absents d\'ACMA' => array_map(fn (object $v): array => [
                'Matricule' => $v->matricule, 'Nom' => $this->nomPlateforme($v->matricule), 'Promotion' => $v->promotion,
                'Référence' => $v->reference_brute, 'Date' => $v->date, 'Montant' => $v->montant, 'Mode' => $v->mode,
            ], $absentsAcma),
            'Auditeurs hors plateforme' => $this->horsPlateforme($horsPlateforme),
            'Inscriptions' => $this->inscriptions(),
            'Notes 2026-2027' => $this->notes(),
        ];
        $comptes = [
            'paiements_acma' => count($acma), 'versements_plateforme' => count($versements),
            'absents_de_la_plateforme' => count($absentsPlateforme), 'montants_differents' => count($differents),
            'absents_d_acma' => count($absentsAcma), 'paiements_hors_plateforme' => count($horsPlateforme),
            'ecarts_d_inscription' => count($feuilles['Inscriptions']),
        ];
        $chemin = $this->ecrire($feuilles, $sortie);
        activity(Journaux::ARCHIVES)->causedBy($auteur)->event('ecarts_2026_produits')->withProperties($comptes)->log('Rapport des écarts 2026-2027 produit');

        return ['chemin' => $chemin, 'synthese' => $feuilles['Synthèse'], 'comptes' => $comptes];
    }

    private function charger(): void
    {
        $this->plateforme = [];
        foreach (DB::table('auditeurs as a')->join('candidatures as c', 'c.id', '=', 'a.candidature_id')
            ->leftJoin('campagnes_candidature as k', 'k.id', '=', 'c.campagne_id')->whereNotNull('c.matricule')
            ->get(['a.id', 'c.matricule', 'c.nom', 'c.prenom', 'k.promotion_numero']) as $p) {
            $this->plateforme['k'.NormalisationAcma::matricule($p->matricule)] = $p;
        }
        $this->nomsAcma = [];
        foreach (LectureTransit::table('etudiant')->get(['matricule', 'nometudiant']) as $e) {
            $this->nomsAcma['k'.NormalisationAcma::matricule($e->matricule)] = (string) NormalisationAcma::nomComplet($e->nometudiant);
        }
        // Année d'entrée en M1 : celle de la première inscription M1, ou l'année qui précède une première inscription en M2.
        $this->premiereAnneeAcma = [];
        foreach (LectureTransit::table('inscription_academic')->whereRaw("coalesce(trim(niveau), '') <> ''")->get(['matricule', 'anneeacademic', 'niveau']) as $i) {
            $annee = NormalisationAcma::annee($i->anneeacademic);
            if ($annee === null) {
                continue;
            }
            $debut = (int) substr($annee, 0, 4) - (trim((string) $i->niveau) === 'FPTC4' ? 0 : 1);
            $entree = $debut.'-'.($debut + 1);
            $cle = 'k'.NormalisationAcma::matricule($i->matricule);
            if (! isset($this->premiereAnneeAcma[$cle]) || $entree < $this->premiereAnneeAcma[$cle]) {
                $this->premiereAnneeAcma[$cle] = $entree;
            }
        }
    }

    /** Promotion : celle de la plateforme si l'auditeur y est, sinon d'après son année d'entrée en M1 dans ACMA. */
    private function promotion(string $matricule): string
    {
        $p = $this->plateforme['k'.$matricule] ?? null;
        if ($p !== null && $p->promotion_numero !== null) {
            return match (true) {
                (int) $p->promotion_numero >= 14 => 'P14',
                (int) $p->promotion_numero === 13 => 'P13',
                default => 'P12 et antérieures',
            };
        }

        return match ($this->premiereAnneeAcma['k'.$matricule] ?? null) {
            CorrespondancesAcma::ANNEE_EN_COURS => 'P14',
            CorrespondancesAcma::DERNIERE_ANNEE_REPRISE => 'P13',
            default => 'P12 et antérieures',
        };
    }

    public static function reference(?string $brute): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim((string) $brute)));
    }

    /**
     * @param  array<int, object>  $acma
     * @param  array<int, object>  $versements
     * @return array{0: array<string, true>, 1: list<array<string, mixed>>}
     */
    private function rapprocher(array $acma, array $versements): array
    {
        $apparies = [];
        $differents = [];
        // Par référence : la liste suit les rapprochements déjà faits.
        $libres = function (string $m) use (&$apparies, $versements): array {
            return array_filter($versements, fn (object $v): bool => $v->matricule === $m && ! isset($apparies['v'.$v->id]));
        };
        $parRecu = [];
        foreach ($acma as $l) {
            if ($l->reference !== '') {
                $parRecu[$l->matricule.'|'.$l->reference][] = $l;
            }
        }

        // 1. Même référence normalisée : le reçu ACMA (toutes ses lignes) contre le versement de même référence.
        foreach ($parRecu as $lignes) {
            $m = $lignes[0]->matricule;
            foreach ($libres($m) as $v) {
                if ($v->reference === $lignes[0]->reference) {
                    $total = array_sum(array_map(fn (object $l): int => $l->total, $lignes));
                    $apparies['v'.$v->id] = true;
                    foreach ($lignes as $l) {
                        $apparies['a'.$l->id] = true;
                    }
                    if ($total !== $v->montant) {
                        $differents[] = ['Matricule' => $m, 'Nom' => $this->nomPlateforme($m), 'Promotion' => $v->promotion, 'Reçu ACMA' => $lignes[0]->recu,
                            'Référence plateforme' => $v->reference_brute, 'Total ACMA' => $total, 'Montant plateforme' => $v->montant, 'Écart' => $total - $v->montant];
                    }
                    break;
                }
            }
        }
        // 2. Même total à 7 jours près.
        foreach ($acma as $l) {
            if (isset($apparies['a'.$l->id]) || $l->date === null) {
                continue;
            }
            foreach ($libres($l->matricule) as $v) {
                if ($v->montant === $l->total && abs(CarbonImmutable::parse($v->date)->diffInDays(CarbonImmutable::parse($l->date))) <= self::JOURS) {
                    $apparies['v'.$v->id] = $apparies['a'.$l->id] = true;
                    break;
                }
            }
        }
        // 3. Somme des lignes restantes d'un même reçu contre un versement.
        foreach ($parRecu as $lignes) {
            $restantes = array_filter($lignes, fn (object $l): bool => ! isset($apparies['a'.$l->id]));
            if (count($restantes) < 2) {
                continue;
            }
            $total = array_sum(array_map(fn (object $l): int => $l->total, $restantes));
            foreach ($libres($lignes[0]->matricule) as $v) {
                if ($v->montant === $total) {
                    $apparies['v'.$v->id] = true;
                    foreach ($restantes as $l) {
                        $apparies['a'.$l->id] = true;
                    }
                    break;
                }
            }
        }

        return [$apparies, $differents];
    }

    /**
     * @param  array<int, object>  $acma
     * @param  array<int, object>  $versements
     * @return list<array<string, int|string>>
     */
    private function synthese(array $acma, array $versements): array
    {
        $lignes = [];
        foreach (self::PROMOTIONS as $p) {
            $a = array_filter($acma, fn (object $l): bool => $l->promotion === $p);
            $v = array_filter($versements, fn (object $x): bool => $x->promotion === $p);
            $total = array_sum(array_map(fn (object $l): int => $l->total, $a));
            $plateforme = array_sum(array_map(fn (object $x): int => $x->montant, $v));
            $lignes[] = [
                'Promotion' => $p, 'Lignes ACMA' => count($a), 'Colonne scolarité ACMA' => array_sum(array_map(fn (object $l): int => $l->montant, $a)),
                'Total des 5 colonnes ACMA' => $total, 'Versements plateforme' => count($v), 'Total plateforme' => $plateforme, 'Écart (ACMA − plateforme)' => $total - $plateforme,
            ];
        }

        return $lignes;
    }

    /** @return array<string, mixed> */
    private function ligneAcma(object $l): array
    {
        return ['Matricule' => $l->matricule, 'Nom ACMA' => $this->nomsAcma['k'.$l->matricule] ?? '', 'Promotion' => $l->promotion, 'Niveau' => $l->niveau,
            'Reçu' => $l->recu, 'Date' => $l->date, 'Phase' => $l->phase, 'Colonne scolarité' => $l->montant, 'Total des 5 colonnes' => $l->total];
    }

    /**
     * @param  list<object>  $lignes
     * @return list<array<string, mixed>>
     */
    private function horsPlateforme(array $lignes): array
    {
        $parAuditeur = [];
        foreach (LectureTransit::table('inscription_academic')->where('anneeacademic', CorrespondancesAcma::ANNEE_EN_COURS)->get(['matricule', 'niveau']) as $i) {
            $m = (string) NormalisationAcma::matricule($i->matricule);
            if (! isset($this->plateforme['k'.$m])) {
                $parAuditeur['k'.$m] ??= ['Matricule' => $m, 'Nom ACMA' => $this->nomsAcma['k'.$m] ?? '', 'Niveau 2026-2027' => trim((string) $i->niveau), 'Paiements' => 0, 'Total des 5 colonnes' => 0];
            }
        }
        foreach ($lignes as $l) {
            $parAuditeur['k'.$l->matricule] ??= ['Matricule' => $l->matricule, 'Nom ACMA' => $this->nomsAcma['k'.$l->matricule] ?? '', 'Niveau 2026-2027' => '', 'Paiements' => 0, 'Total des 5 colonnes' => 0];
            $parAuditeur['k'.$l->matricule]['Paiements']++;
            $parAuditeur['k'.$l->matricule]['Total des 5 colonnes'] += $l->total;
        }

        return array_values($parAuditeur);
    }

    /** Inscriptions 2026-2027 d'ACMA sans inscription de la plateforme, l'inverse, et les écarts de niveau ou d'option. @return list<array<string, string>> */
    private function inscriptions(): array
    {
        $acma = [];
        foreach (LectureTransit::table('inscription_academic')->where('anneeacademic', CorrespondancesAcma::ANNEE_EN_COURS)->get(['matricule', 'niveau']) as $i) {
            $acma['k'.NormalisationAcma::matricule($i->matricule)][] = trim((string) $i->niveau);
        }
        $plateforme = [];
        foreach (DB::table('inscriptions_annuelles as i')->join('auditeurs as a', 'a.id', '=', 'i.auditeur_id')->join('candidatures as c', 'c.id', '=', 'a.candidature_id')
            ->leftJoin('parcours as p', 'p.id', '=', 'i.parcours_id')->where('i.annee_academique', CorrespondancesAcma::ANNEE_EN_COURS)
            ->get(['c.matricule', 'i.niveau', 'p.code']) as $i) {
            $plateforme['k'.NormalisationAcma::matricule($i->matricule)] = ['niveau' => (string) $i->niveau, 'parcours' => $i->code];
        }
        $lignes = [];
        foreach ($acma as $cle => $niveaux) {
            $m = substr((string) $cle, 1);
            $p = $plateforme[$cle] ?? null;
            if (! isset($this->plateforme[$cle])) {
                continue; // Auditeur absent de la plateforme : feuille « Auditeurs hors plateforme ».
            }
            if ($p === null) {
                $lignes[] = ['Matricule' => $m, 'Nom' => $this->nomPlateforme($m), 'Constat' => 'Inscrit dans ACMA, pas dans la plateforme', 'ACMA' => implode(', ', $niveaux), 'Plateforme' => ''];

                continue;
            }
            $correspondances = array_map(fn (string $n): ?array => CorrespondancesAcma::NIVEAUX[$n] ?? null, $niveaux);
            $concordant = false;
            foreach ($correspondances as $c) {
                $concordant = $concordant || ($c !== null && $c['niveau'] === $p['niveau'] && ($p['parcours'] === null || $c['parcours'] === $p['parcours']));
            }
            if (! $concordant) {
                $lignes[] = ['Matricule' => $m, 'Nom' => $this->nomPlateforme($m), 'Constat' => 'Niveau ou option différents', 'ACMA' => implode(', ', $niveaux), 'Plateforme' => trim($p['niveau'].' '.($p['parcours'] ?? ''))];
            }
        }
        foreach ($plateforme as $cle => $p) {
            if (! isset($acma[$cle])) {
                $m = substr((string) $cle, 1);
                $lignes[] = ['Matricule' => $m, 'Nom' => $this->nomPlateforme($m), 'Constat' => 'Inscrit dans la plateforme, pas dans ACMA', 'ACMA' => '', 'Plateforme' => trim($p['niveau'].' '.($p['parcours'] ?? ''))];
            }
        }

        return $lignes;
    }

    /** Notes 2026-2027 d'ACMA par UE : constat des grilles vides, rien n'est repris. @return list<array<string, int|string>> */
    private function notes(): array
    {
        $lignes = [];
        foreach (LectureTransit::table('notes as n')->join('acma_transit.programe as p', 'p.idprog', '=', 'n.idprog')
            ->where('p.anneeacademic', CorrespondancesAcma::ANNEE_EN_COURS)->groupBy('n.codue')->orderBy('n.codue')
            ->get(['n.codue', DB::raw('count(*) AS lignes'), DB::raw("count(*) FILTER (WHERE coalesce(nullif(n.cc, '')::double precision, 0) <> 0 OR coalesce(nullif(n.tp, '')::double precision, 0) <> 0 OR coalesce(nullif(n.exam, '')::double precision, 0) <> 0 OR coalesce(nullif(n.ratra, '')::double precision, 0) <> 0) AS avec_valeur")]) as $n) {
            $lignes[] = ['UE' => (string) $n->codue, 'Lignes' => (int) $n->lignes, 'Lignes avec une valeur' => (int) $n->avec_valeur,
                'Constat' => (int) $n->avec_valeur === 0 ? 'Grille vide : aucune valeur, non reprise' : 'Valeurs saisies dans ACMA : à ressaisir dans la plateforme'];
        }

        return $lignes;
    }

    private function nomPlateforme(string $matricule): string
    {
        $p = $this->plateforme['k'.$matricule] ?? null;

        return $p === null ? ($this->nomsAcma['k'.$matricule] ?? '') : trim($p->nom.' '.$p->prenom);
    }

    /** @param  array<string, list<array<string, mixed>>>  $feuilles */
    private function ecrire(array $feuilles, ?string $sortie): string
    {
        $classeur = new Spreadsheet;
        $classeur->removeSheetByIndex(0);
        foreach ($feuilles as $titre => $lignes) {
            $feuille = $classeur->createSheet();
            $feuille->setTitle(mb_substr($titre, 0, 31));
            $entetes = $lignes === [] ? ['Aucune ligne'] : array_keys($lignes[0]);
            foreach ($entetes as $c => $entete) {
                $feuille->setCellValueExplicit([$c + 1, 1], (string) $entete, DataType::TYPE_STRING);
            }
            foreach ($lignes as $r => $ligne) {
                foreach (array_values($ligne) as $c => $valeur) {
                    if (is_int($valeur)) {
                        $feuille->setCellValue([$c + 1, $r + 2], $valeur);
                    } else {
                        $feuille->setCellValueExplicit([$c + 1, $r + 2], (string) $valeur, DataType::TYPE_STRING);
                    }
                }
            }
            $feuille->getStyle([1, 1, count($entetes), 1])->getFont()->setBold(true);
        }
        $classeur->setActiveSheetIndex(0);
        ob_start();
        (new Xlsx($classeur))->save('php://output');
        $contenu = (string) ob_get_clean();

        if ($sortie !== null && $sortie !== '') {
            file_put_contents($sortie, $contenu);
            chmod($sortie, 0600);

            return $sortie;
        }
        $chemin = RapportsReprise::DOSSIER.'/ecarts-2026-2027-'.now()->format('Ymd').'.xlsx';
        Storage::disk(RapportsReprise::DISQUE)->put($chemin, $contenu, 'private');
        RapportsReprise::garderLeDernier('ecarts-2026-2027-', $chemin);

        return $chemin;
    }
}
