<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\CorrespondancesAcma;
use App\Services\Acma\DatesAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\NormalisationAcma;
use Illuminate\Support\Facades\DB;

/**
 * Étape 7b (§3.5, §7.7) : paiements d'ACMA jusqu'en 2025-2026, en historique et jamais en versements. Le total d'une
 * ligne est la somme des cinq colonnes (scolarité, inscription, bibliothèque, périscolaire, tenue). Une décimale bloque
 * l'étape. Les anomalies sont signalées, jamais corrigées :
 * - négatif, et sa contrepartie positive de même montant (même auditeur, même année), appariées une pour une ;
 * - total nul ; date de reçu vide, invalide, avant 2013 ou future ; hors de l'année académique à un an près ;
 * - doublon exact (même auditeur, année, montant, date et phase) ; niveau sans inscription cette année-là.
 */
final class EtapePaiements implements Etape
{
    private const COLONNES = ['Montant' => 'montant_scolarite', 'inscriptions' => 'inscriptions', 'bibliotheque' => 'bibliotheque', 'fraisperiscol' => 'fraisperiscol', 'tenuesport' => 'tenuesport'];

    public function nom(): string
    {
        return 'paiements';
    }

    public function executer(ContexteReprise $contexte): array
    {
        $dossiers = DB::table('acma_dossiers')->whereNull('retire_de_la_source_at')->pluck('id', 'matricule')->all();
        $inscriptions = [];
        foreach (DB::table('acma_inscriptions')->whereNull('retire_de_la_source_at')->get(['dossier_id', 'annee', 'niveau_acma']) as $i) {
            $inscriptions["{$i->dossier_id}|{$i->annee}|{$i->niveau_acma}"] = true;
        }
        $aujourdhui = $contexte->maintenant->format('Y-m-d');
        $ignorees = ['annee_invalide' => 0, 'annee_non_reprise' => 0, 'dossier_exclu' => 0];
        $lignes = $doublons = [];
        foreach (LectureTransit::lignes('du', ['IddroitU', 'matricule', 'Niveau', 'anneacademic', 'Montant', 'inscriptions', 'bibliotheque', 'fraisperiscol', 'tenuesport', 'dateRecu', 'NumeroRecu', 'NomBanque', 'codephase', 'username']) as $d) {
            $annee = NormalisationAcma::annee($d->anneacademic);
            if ($annee === null) {
                $ignorees['annee_invalide']++;

                continue;
            }
            if (! CorrespondancesAcma::anneeReprise($annee)) {
                $ignorees['annee_non_reprise']++;

                continue;
            }
            $matricule = (string) NormalisationAcma::matricule($d->matricule);
            $dossier = $dossiers[$matricule] ?? null;
            if ($dossier === null) {
                $ignorees['dossier_exclu']++;

                continue;
            }
            $niveauAcma = trim((string) $d->Niveau);
            $montants = [];
            foreach (self::COLONNES as $source => $colonne) {
                $montants[$colonne] = NormalisationAcma::montant($d->{$source}, "paiement {$d->IddroitU}, colonne {$source}") ?? 0;
            }
            $total = array_sum($montants);
            $date = DatesAcma::date($d->dateRecu);
            $ligne = [
                'id_acma' => (int) $d->IddroitU,
                'dossier_id' => (int) $dossier,
                'annee' => $annee,
                'niveau_acma' => $niveauAcma,
                'niveau' => CorrespondancesAcma::niveau($niveauAcma)['niveau'],
                'phase' => NormalisationAcma::texte($d->codephase),
                ...$montants,
                'total' => $total,
                'date_recu' => $date,
                'date_recu_source' => NormalisationAcma::texte($d->dateRecu),
                'numero_recu' => NormalisationAcma::texte($d->NumeroRecu),
                'banque' => NormalisationAcma::texte($d->NomBanque),
                'saisi_par_acma' => NormalisationAcma::texte($d->username),
                'negatif' => min($montants) < 0,
                'contrepartie_id_acma' => null,
                'total_nul' => $total === 0,
                'date_aberrante' => $date === null || $date < '2013-01-01' || $date > $aujourdhui,
                'hors_annee' => $date !== null && DatesAcma::horsAnnee($date, $annee),
                'doublon_exact' => false,
                'niveau_sans_inscription' => ! isset($inscriptions["{$dossier}|{$annee}|{$niveauAcma}"]),
            ];
            $cleDoublon = implode('|', [$dossier, $annee, $montants['montant_scolarite'], (string) $d->dateRecu, (string) $d->codephase]);
            $doublons[$cleDoublon][] = $ligne['id_acma'];
            $lignes[$ligne['id_acma']] = $ligne;
        }

        foreach ($doublons as $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $id) {
                    $lignes[$id]['doublon_exact'] = true;
                }
            }
        }
        $apparies = $this->apparier($lignes);

        $ecriture = new EcritureArchive('acma_paiements', 'id_acma', $contexte->maintenant);
        ksort($lignes);
        foreach ($lignes as $ligne) {
            $ecriture->ajouter($ligne);
        }
        $anomalies = ['negatifs' => 0, 'apparies' => $apparies, 'total_nul' => 0, 'date_aberrante' => 0, 'hors_annee' => 0, 'doublon_exact' => 0, 'niveau_sans_inscription' => 0];
        foreach ($lignes as $l) {
            $anomalies['negatifs'] += (int) $l['negatif'];
            foreach (['total_nul', 'date_aberrante', 'hors_annee', 'doublon_exact', 'niveau_sans_inscription'] as $drapeau) {
                $anomalies[$drapeau] += (int) $l[$drapeau];
            }
        }

        return ['paiements' => $ecriture->terminer(), 'ignorees' => $ignorees, 'anomalies' => $anomalies];
    }

    /**
     * Annulations à la manière d'ACMA : un négatif et un positif de même montant (colonne scolarité), même auditeur et
     * même année, appariés un pour un dans l'ordre des identifiants.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     */
    private function apparier(array &$lignes): int
    {
        $positifs = [];
        foreach ($lignes as $id => $l) {
            if ($l['montant_scolarite'] > 0) {
                $positifs["{$l['dossier_id']}|{$l['annee']}|{$l['montant_scolarite']}"][] = $id;
            }
        }
        foreach ($positifs as &$file) {
            sort($file);
        }
        unset($file);
        $negatifs = array_filter($lignes, fn (array $l): bool => $l['montant_scolarite'] < 0);
        ksort($negatifs);
        $apparies = 0;
        foreach (array_keys($negatifs) as $id) {
            $l = $lignes[$id];
            $cle = "{$l['dossier_id']}|{$l['annee']}|".(-$l['montant_scolarite']);
            $contrepartie = isset($positifs[$cle]) ? array_shift($positifs[$cle]) : null;
            if ($contrepartie !== null) {
                $lignes[$id]['contrepartie_id_acma'] = $contrepartie;
                $lignes[$contrepartie]['contrepartie_id_acma'] = $id;
                $apparies++;
            }
        }

        return $apparies;
    }
}
