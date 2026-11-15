<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\CorrespondancesAcma;
use App\Services\Acma\DatesAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\NormalisationAcma;
use Illuminate\Support\Facades\DB;

/**
 * Étape 8b (§7.9) : documents délivrés par ACMA, listés sans fichier (ACMA n'en garde pas). Les copies du nom et de la
 * date de naissance que portent ces tables ne sont pas reprises : l'identité est celle du dossier.
 */
final class EtapeDocuments implements Etape
{
    /** Table ACMA → [type, colonne de date, colonnes de détails (source → clé)]. */
    private const SOURCES = [
        'certificat' => ['certificat', 'Datedujour', ['specialite' => 'specialite', 'Parcourt' => 'parcours']],
        'attestationr' => ['attestation_reussite', 'datedujour', ['specialite' => 'specialite', 'session' => 'session', 'Memoire' => 'memoire', 'Parcourt' => 'parcours']],
        'prisecours' => ['lettre_stage', 'datedujour', ['Specialite' => 'specialite', 'DebutPriseCours' => 'debut', 'FinpriseCours' => 'fin', 'themestage' => 'theme', 'structureAcueil' => 'structure', 'anneeacademique' => 'annee_academique', 'dureestage' => 'duree', 'Parcourt' => 'parcours']],
        'nonperceptionb' => ['non_perception_bourse', 'datedujour', ['Parcourt' => 'parcours']],
        'misedispos' => ['mise_a_disposition', 'jourOperation', ['specialite' => 'specialite', 'session' => 'session', 'NoteMemoire' => 'note_memoire', 'Mention' => 'mention', 'Memoire' => 'memoire', 'Parcourt' => 'parcours']],
    ];

    private const IDENTIFIANTS = ['certificat' => 'IdCertificat', 'attestationr' => 'Idattes', 'prisecours' => 'IdPrise', 'nonperceptionb' => 'Idnonper', 'misedispos' => 'Idmise'];

    public function nom(): string
    {
        return 'documents';
    }

    public function executer(ContexteReprise $contexte): array
    {
        $dossiers = DB::table('acma_dossiers')->whereNull('retire_de_la_source_at')->pluck('id', 'matricule')->all();
        $ecriture = new EcritureArchive('acma_documents', 'cle_source', $contexte->maintenant);
        $parType = [];
        $exclus = 0;
        foreach (self::SOURCES as $table => [$type, $colonneDate, $details]) {
            $identifiant = self::IDENTIFIANTS[$table];
            foreach (LectureTransit::lignes($table, array_merge([$identifiant, 'Matricule', 'Niveau', $colonneDate], array_keys($details))) as $d) {
                $dossier = $dossiers[(string) NormalisationAcma::matricule($d->Matricule)] ?? null;
                if ($dossier === null) {
                    $exclus++;

                    continue;
                }
                $valeurs = [];
                foreach ($details as $source => $cle) {
                    $v = NormalisationAcma::texte($d->{$source});
                    if ($v !== null) {
                        $valeurs[$cle] = $v;
                    }
                }
                $dateBrute = NormalisationAcma::texte($d->{$colonneDate});
                // Le « Datedujour » des certificats porte en fait l'année académique (§3.1).
                if ($dateBrute !== null && NormalisationAcma::annee($dateBrute) !== null) {
                    $valeurs['annee_academique'] ??= $dateBrute;
                }
                $niveauAcma = NormalisationAcma::texte($d->Niveau);
                $ecriture->ajouter([
                    'cle_source' => $type.'|'.$d->{$identifiant},
                    'type' => $type,
                    'id_acma' => (int) $d->{$identifiant},
                    'dossier_id' => (int) $dossier,
                    'niveau_acma' => $niveauAcma,
                    'niveau' => CorrespondancesAcma::NIVEAUX[(string) $niveauAcma]['niveau'] ?? null,
                    'date' => DatesAcma::date($dateBrute) ?? DatesAcma::texte($dateBrute),
                    'date_source' => $dateBrute === null ? null : mb_substr($dateBrute, 0, 45),
                    'details' => $valeurs === [] ? null : json_encode($valeurs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
                $parType[$type] = ($parType[$type] ?? 0) + 1;
            }
        }
        ksort($parType);

        return ['documents' => $ecriture->terminer(), 'par_type' => $parType, 'dossier_exclu' => $exclus];
    }
}
