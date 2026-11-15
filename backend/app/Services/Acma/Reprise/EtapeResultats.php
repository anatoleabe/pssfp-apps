<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\CorrespondancesAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\NormalisationAcma;
use Illuminate\Support\Facades\DB;

/**
 * Étape 5 (§5.6, §7.5) : résultats par UE d'ACMA, notes reconstituées depuis l'historique, notes non rattachées,
 * recalculs « selon ACMA » (indicatifs) et décision d'archive.
 *
 * - L'année se lit dans le programme, jamais dans la note.
 * - Un couple (programme, auditeur) absent de `notes` reprend sa dernière version de l'historique (origine `historique`).
 * - Deux fiches fusionnées qui notent la même ligne de programme : les deux résultats restent, signalés en conflit, et
 *   n'entrent pas dans les crédits.
 * - La décision n'est jamais recalculée : celle d'ACMA, ou « non enregistrée » (cohortes du §3.3, inscription sans note).
 */
final class EtapeResultats implements Etape
{
    private const COLONNES_NOTES = ['idnote', 'idprog', 'codue', 'matricule', 'cc', 'tp', 'exam', 'ratra', 'ok', 'dateoperation'];

    private const COLONNES_HISTORIQUE = ['idnotes', 'idprog', 'codue', 'matricule', 'cc', 'tp', 'exem', 'Rattra', 'ok', 'changedon'];

    /** @var array<int, object> */
    private array $unites = [];

    /** @var array<int, string> */
    private array $programmes = [];

    /** @var array<string, int> */
    private array $dossiers = [];

    /** @var array<string, int> */
    private array $inscriptions = [];

    public function nom(): string
    {
        return 'resultats';
    }

    public function executer(ContexteReprise $contexte): array
    {
        $this->charger();
        $ignorees = ['dossier_exclu' => 0, 'annee_non_reprise' => 0];

        // 1. Couples présents dans `notes`, dernière version des couples seulement dans l'historique, paires (inscription, unité).
        $couplesNotes = $paires = [];
        foreach (LectureTransit::lignes('notes', ['idprog', 'matricule']) as $n) {
            $m = (string) NormalisationAcma::matricule($n->matricule);
            $couplesNotes["{$n->idprog}|{$m}"] = true;
            $cible = $this->cible((int) $n->idprog, $m);
            if (isset($cible['paire'])) {
                $paires[$cible['paire']] = ($paires[$cible['paire']] ?? 0) + 1;
            }
        }
        $dernieres = [];
        foreach (LectureTransit::lignes('notes_hist', self::COLONNES_HISTORIQUE, 5000) as $h) {
            $m = (string) NormalisationAcma::matricule($h->matricule);
            $couple = "{$h->idprog}|{$m}";
            if (isset($couplesNotes[$couple])) {
                continue;
            }
            $precedente = $dernieres[$couple] ?? null;
            if ($precedente === null || [(string) $h->changedon, (int) $h->idnotes] > [(string) $precedente->changedon, (int) $precedente->idnotes]) {
                $dernieres[$couple] = $h;
            }
        }
        foreach ($dernieres as $h) {
            $cible = $this->cible((int) $h->idprog, (string) NormalisationAcma::matricule($h->matricule));
            if (isset($cible['paire'])) {
                $paires[$cible['paire']] = ($paires[$cible['paire']] ?? 0) + 1;
            }
        }
        unset($couplesNotes);

        // 2. Écriture des résultats et des notes non rattachées ; agrégats par inscription.
        $resultats = new EcritureArchive('acma_resultats_ue', 'cle_source', $contexte->maintenant);
        $nonRattachees = new EcritureArchive('acma_notes_non_rattachees', 'cle_source', $contexte->maintenant);
        $agregats = [];
        $origines = ['notes' => 0, 'historique' => 0];
        $conflits = $horsBornes = 0;
        $placer = function (string $origine, object $n, ?int $idNote, ?string $exam, ?string $ratra, ?string $horodatage) use (&$ignorees, &$agregats, &$origines, &$conflits, &$horsBornes, $paires, $resultats, $nonRattachees): void {
            $m = (string) NormalisationAcma::matricule($n->matricule);
            $cible = $this->cible((int) $n->idprog, $m);
            if (isset($cible['ignoree'])) {
                $ignorees[$cible['ignoree']]++;

                return;
            }
            $valeurs = [LectureTransit::nombre($n->cc), LectureTransit::nombre($n->tp), LectureTransit::nombre($exam), LectureTransit::nombre($ratra)];
            if (isset($cible['dossier'])) {
                $nonRattachees->ajouter([
                    'cle_source' => $origine === 'notes' ? 'n:'.$idNote : "h:{$n->idprog}:{$m}",
                    'dossier_id' => $cible['dossier'],
                    'id_prog_acma' => (int) $n->idprog,
                    'code_ue' => NormalisationAcma::texte($n->codue),
                    'origine' => $origine,
                    'motif' => $cible['motif'],
                    'id_note_acma' => $idNote,
                    'cc' => $valeurs[0], 'tp' => $valeurs[1], 'examen' => $valeurs[2], 'rattrapage' => $valeurs[3],
                    'ok_acma' => is_numeric((string) $n->ok) ? (int) $n->ok : null,
                    'horodatage_source' => $horodatage,
                ]);

                return;
            }
            $unite = $cible['unite'];
            $calcul = self::calculer($valeurs, (int) $n->ok, $unite);
            $enConflit = ($paires[$cible['paire']] ?? 0) > 1;
            $origines[$origine]++;
            $conflits += (int) $enConflit;
            $horsBornes += (int) $calcul['hors_bornes'];
            $resultats->ajouter([
                'cle_source' => $origine === 'notes' ? 'n:'.$idNote : "h:{$n->idprog}:{$m}",
                'inscription_id' => $cible['inscription'],
                'unite_id' => (int) $unite->id,
                'matricule_source' => mb_substr(trim((string) $n->matricule), 0, 20),
                'id_note_acma' => $idNote,
                'origine' => $origine,
                'cc_brut' => $valeurs[0], 'tp_brut' => $valeurs[1], 'examen_brut' => $valeurs[2], 'rattrapage_brut' => $valeurs[3],
                'ok_acma' => is_numeric((string) $n->ok) ? (int) $n->ok : null,
                'horodatage_source' => $horodatage,
                'en_conflit' => $enConflit,
            ] + $calcul);
            $a = &$agregats[$cible['inscription']];
            $a ??= ['acquis' => 0, 'somme' => 0.0, 'poids' => 0, 'rattrapages' => 0, 'conflits' => 0, 'resultats' => 0];
            $a['resultats']++;
            $a['rattrapages'] += (int) $calcul['rattrapage_applique'];
            $a['conflits'] += (int) $enConflit;
            if (! $enConflit && $calcul['moyenne'] !== null && (int) $unite->credits > 0) {
                $a['acquis'] += $calcul['acquise'] ? (int) $unite->credits : 0;
                $a['somme'] += $calcul['moyenne'] * (int) $unite->credits;
                $a['poids'] += (int) $unite->credits;
            }
        };
        foreach (LectureTransit::lignes('notes', self::COLONNES_NOTES, 5000) as $n) {
            $placer('notes', $n, (int) $n->idnote, $n->exam, $n->ratra, self::texte($n->dateoperation));
        }
        foreach ($dernieres as $h) {
            $placer('historique', $h, (int) $h->idnotes, $h->exem, $h->Rattra, self::texte($h->changedon));
        }
        unset($dernieres);

        $comptesResultats = $resultats->terminer();
        $comptesNonRattachees = $nonRattachees->terminer();
        $recalculs = $this->recalculer($agregats, $contexte);
        $contexte->infos['conflits'] = $conflits;

        return [
            'resultats' => $comptesResultats + ['origine_notes' => $origines['notes'], 'origine_historique' => $origines['historique'], 'en_conflit' => $conflits, 'hors_bornes' => $horsBornes],
            'notes_non_rattachees' => $comptesNonRattachees,
            'ignorees' => $ignorees,
            'inscriptions_recalculees' => $recalculs,
        ];
    }

    private function charger(): void
    {
        $this->unites = DB::table('acma_unites')->whereNull('retire_de_la_source_at')
            ->get(['id', 'id_prog_acma', 'annee', 'niveau_acma', 'credits', 'pcc', 'ptp', 'pexam'])->keyBy('id_prog_acma')->all();
        $this->programmes = LectureTransit::table('programe')->pluck('anneeacademic', 'idprog')->all();
        $this->dossiers = DB::table('acma_dossiers')->whereNull('retire_de_la_source_at')->pluck('id', 'matricule')->all();
        $this->inscriptions = [];
        foreach (DB::table('acma_inscriptions')->whereNull('retire_de_la_source_at')->get(['id', 'dossier_id', 'annee', 'niveau_acma']) as $i) {
            $this->inscriptions["{$i->dossier_id}|{$i->annee}|{$i->niveau_acma}"] = (int) $i->id;
        }
    }

    /**
     * Où va une note : ['inscription', 'unite', 'paire'], ['dossier', 'motif'] (non rattachée : programme disparu, ou
     * aucune inscription de l'auditeur à l'année et au niveau du programme ; gardée, hors crédits et hors relevés) ou
     * ['ignoree' => motif].
     *
     * @return array<string, mixed>
     */
    private function cible(int $idProg, string $matricule): array
    {
        $dossier = $this->dossiers[$matricule] ?? null;
        if ($dossier === null) {
            return ['ignoree' => 'dossier_exclu'];
        }
        $unite = $this->unites[$idProg] ?? null;
        if ($unite === null) {
            return isset($this->programmes[$idProg]) ? ['ignoree' => 'annee_non_reprise'] : ['dossier' => (int) $dossier, 'motif' => 'programme_disparu'];
        }
        $inscription = $this->inscriptions["{$dossier}|{$unite->annee}|{$unite->niveau_acma}"] ?? null;
        if ($inscription === null) {
            return ['dossier' => (int) $dossier, 'motif' => 'sans_inscription'];
        }

        return ['inscription' => $inscription, 'unite' => $unite, 'paire' => "{$inscription}|{$unite->id}"];
    }

    /**
     * Règles d'ACMA (§5.6) : moyenne = pcc × CC + ptp × TP + pexam × examen ; si `ok` = 1, le rattrapage remplace la
     * moyenne ; acquise à 12. Une note hors de 0 à 20 n'entre pas dans le calcul (colonne bornée vide, signalée).
     *
     * @param  array{0: float|null, 1: float|null, 2: float|null, 3: float|null}  $brutes
     * @return array{cc: float|null, tp: float|null, examen: float|null, rattrapage: float|null, rattrapage_applique: bool, session: string, moyenne: float|null, credits: int|null, acquise: bool|null, hors_bornes: bool}
     */
    public static function calculer(array $brutes, int $ok, object $unite): array
    {
        $bornees = array_map(fn (?float $x): ?float => $x !== null && $x >= 0 && $x <= 20 ? round($x, 2) : null, $brutes);
        $horsBornes = false;
        foreach ($brutes as $i => $x) {
            $horsBornes = $horsBornes || ($x !== null && $bornees[$i] === null);
        }
        $applique = $ok === 1 && $bornees[3] !== null;
        if ($applique) {
            $moyenne = $bornees[3];
        } elseif ($bornees[0] === null && $bornees[1] === null && $bornees[2] === null) {
            $moyenne = null;
        } else {
            $moyenne = round((float) $unite->pcc * (float) $bornees[0] + (float) $unite->ptp * (float) $bornees[1] + (float) $unite->pexam * (float) $bornees[2], 2);
            $moyenne = $moyenne >= 0 && $moyenne <= 20 ? $moyenne : null;
        }

        return [
            'cc' => $bornees[0], 'tp' => $bornees[1], 'examen' => $bornees[2], 'rattrapage' => $bornees[3],
            'rattrapage_applique' => $applique,
            'session' => $applique ? 'rattrapage' : 'normale',
            'moyenne' => $moyenne,
            'credits' => $unite->credits === null ? null : (int) $unite->credits,
            'acquise' => $moyenne === null ? null : $moyenne >= CorrespondancesAcma::SEUIL,
            'hors_bornes' => $horsBornes,
        ];
    }

    /**
     * Recalculs et décision d'archive par inscription ; seules les inscriptions dont une valeur change sont écrites.
     *
     * @param  array<int, array<string, int|float>>  $agregats
     * @return array{modifiees: int, inchangees: int}
     */
    private function recalculer(array $agregats, ContexteReprise $contexte): array
    {
        $creditsProgramme = [];
        foreach ($this->unites as $u) {
            $creditsProgramme["{$u->annee}|{$u->niveau_acma}"] = ($creditsProgramme["{$u->annee}|{$u->niveau_acma}"] ?? 0) + (int) $u->credits;
        }
        $comptes = ['modifiees' => 0, 'inchangees' => 0];
        $colonnes = ['id', 'annee', 'niveau_acma', 'admis_acma', 'total_credits_acma', 'credits_programme', 'credits_acquis', 'moyenne_recalculee', 'nb_rattrapages', 'nb_conflits', 'decision', 'decision_motif', 'signalements'];
        foreach (DB::table('acma_inscriptions')->whereNull('retire_de_la_source_at')->select($colonnes)->lazyById(1000) as $i) {
            $a = $agregats[$i->id] ?? null;
            $programme = $creditsProgramme["{$i->annee}|{$i->niveau_acma}"] ?? null;
            $acquis = $a === null ? null : (int) $a['acquis'];
            if (CorrespondancesAcma::sansDecision($i->annee, $i->niveau_acma)) {
                [$decision, $motif] = ['non_enregistree', 'cohorte_sans_decision'];
            } elseif ($a === null) {
                [$decision, $motif] = ['non_enregistree', 'sans_note'];
            } elseif ($i->admis_acma === null) {
                [$decision, $motif] = ['non_enregistree', 'admis_absent'];
            } else {
                [$decision, $motif] = [$i->admis_acma ? 'admis' : 'non_admis', null];
            }
            $signalements = [];
            if ($i->admis_acma && $acquis !== null && $programme !== null && $acquis < $programme) {
                $signalements[] = 'admis_sans_toutes_les_ue';
            }
            if ($i->total_credits_acma !== null && $acquis !== null && (int) $i->total_credits_acma !== $acquis) {
                $signalements[] = 'credits_acma_differents';
            }
            if ($a !== null && $a['conflits'] > 0) {
                $signalements[] = 'conflit_de_notes';
            }
            $nouveau = [
                'credits_programme' => $programme,
                'credits_acquis' => $acquis,
                'moyenne_recalculee' => $a === null || $a['poids'] === 0 ? null : round($a['somme'] / $a['poids'], 2),
                'nb_rattrapages' => $a === null ? null : (int) $a['rattrapages'],
                'nb_conflits' => $a === null ? null : (int) $a['conflits'],
                'decision' => $decision,
                'decision_motif' => $motif,
                'signalements' => $signalements === [] ? null : $signalements,
            ];
            $actuel = [
                'credits_programme' => $i->credits_programme === null ? null : (int) $i->credits_programme,
                'credits_acquis' => $i->credits_acquis === null ? null : (int) $i->credits_acquis,
                'moyenne_recalculee' => $i->moyenne_recalculee === null ? null : round((float) $i->moyenne_recalculee, 2),
                'nb_rattrapages' => $i->nb_rattrapages === null ? null : (int) $i->nb_rattrapages,
                'nb_conflits' => $i->nb_conflits === null ? null : (int) $i->nb_conflits,
                'decision' => $i->decision,
                'decision_motif' => $i->decision_motif,
                'signalements' => $i->signalements === null ? null : json_decode((string) $i->signalements, true),
            ];
            if ($nouveau === $actuel) {
                $comptes['inchangees']++;

                continue;
            }
            $nouveau['signalements'] = $nouveau['signalements'] === null ? null : json_encode($nouveau['signalements'], JSON_THROW_ON_ERROR);
            DB::table('acma_inscriptions')->where('id', $i->id)->update($nouveau + ['updated_at' => $contexte->maintenant]);
            $comptes['modifiees']++;
        }

        return $comptes;
    }

    private static function texte(?string $v): ?string
    {
        $t = trim((string) $v);

        return $t === '' ? null : mb_substr($t, 0, 30);
    }
}
