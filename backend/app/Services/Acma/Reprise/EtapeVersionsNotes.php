<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\DatesAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\NormalisationAcma;
use Illuminate\Support\Facades\DB;

/**
 * Étape 6 (§7.5, Q5) : versions successives des notes, consultables depuis une ligne de résultat. Les versions d'un
 * programme de 2026-2027 et celles des fiches exclues ne sont pas reprises.
 */
final class EtapeVersionsNotes implements Etape
{
    public function nom(): string
    {
        return 'versions_notes';
    }

    public function executer(ContexteReprise $contexte): array
    {
        $unites = DB::table('acma_unites')->whereNull('retire_de_la_source_at')->pluck('id', 'id_prog_acma')->all();
        $programmes = LectureTransit::table('programe')->pluck('anneeacademic', 'idprog')->all();
        $dossiers = DB::table('acma_dossiers')->whereNull('retire_de_la_source_at')->pluck('id', 'matricule')->all();
        $ignorees = ['dossier_exclu' => 0, 'annee_non_reprise' => 0];
        $sansProgramme = $dateInvalide = 0;

        // Une version en double dans l'export (même note, même seconde) garde la première et se compte (C12).
        $ecriture = new EcritureArchive('acma_versions_notes', 'cle_source', $contexte->maintenant, ignorerDoublons: true);
        foreach (LectureTransit::lignes('notes_hist', ['idnotes', 'changedon', 'idprog', 'codue', 'matricule', 'cc', 'tp', 'exem', 'Rattra', 'ok', 'validation'], 5000) as $h) {
            $dossier = $dossiers[(string) NormalisationAcma::matricule($h->matricule)] ?? null;
            if ($dossier === null) {
                $ignorees['dossier_exclu']++;

                continue;
            }
            $idProg = is_numeric((string) $h->idprog) ? (int) $h->idprog : null;
            $unite = $idProg === null ? null : ($unites[$idProg] ?? null);
            if ($unite === null && $idProg !== null && isset($programmes[$idProg])) {
                $ignorees['annee_non_reprise']++;

                continue;
            }
            $sansProgramme += (int) ($unite === null);
            $horodatage = DatesAcma::horodatage($h->changedon);
            $dateInvalide += (int) ($horodatage === null);
            $ecriture->ajouter([
                'cle_source' => "{$h->idnotes}|{$h->changedon}",
                'id_note_acma' => (int) $h->idnotes,
                'horodatage' => $horodatage,
                'horodatage_source' => mb_substr((string) $h->changedon, 0, 30),
                'dossier_id' => (int) $dossier,
                'id_prog_acma' => $idProg,
                'unite_id' => $unite === null ? null : (int) $unite,
                'code_ue' => NormalisationAcma::texte($h->codue),
                'cc' => LectureTransit::nombre($h->cc),
                'tp' => LectureTransit::nombre($h->tp),
                'examen' => LectureTransit::nombre($h->exem),
                'rattrapage' => LectureTransit::nombre($h->Rattra),
                'ok_acma' => is_numeric((string) $h->ok) ? (int) $h->ok : null,
                'validation_acma' => is_numeric((string) $h->validation) ? (int) $h->validation : null,
            ]);
        }

        $versions = $ecriture->terminer();
        $contexte->infos['doublons_versions'] = $versions['doublons'] ?? 0;

        return ['versions' => $versions, 'ignorees' => $ignorees, 'programme_disparu' => $sansProgramme, 'horodatage_invalide' => $dateInvalide];
    }
}
