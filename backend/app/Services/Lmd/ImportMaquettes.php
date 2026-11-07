<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\InscriptionPedagogique;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\MaquetteUe;
use App\Models\Parcours;
use App\Models\RegleEvaluation;
use App\Models\UniteEnseignement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Import des maquettes d'une année depuis le fichier versionné
 * (`resources/lmd/maquettes-AAAA-AAAA.json`). Les maquettes arrivent en
 * brouillon : la Scolarité les relit et les valide.
 *
 * - Une maquette validée n'est jamais touchée.
 * - Un brouillon est remplacé tant qu'aucun auditeur n'y est inscrit.
 * - Un parcours ou une UE déjà connus gardent leur libellé ; un écart est signalé.
 * - Les pondérations sont celles des règles de l'année.
 */
final class ImportMaquettes
{
    /**
     * @param  array<string, mixed>  $document
     * @return array{annee: string, creees: list<string>, remplacees: list<string>, ignorees: array<string, string>, ecarts: list<string>}
     */
    public function importer(array $document, bool $appliquer, ?User $auteur = null): array
    {
        $annee = (string) ($document['annee_academique'] ?? '');
        if (preg_match('/^(\d{4})-(\d{4})$/', $annee, $m) !== 1 || (int) $m[2] !== (int) $m[1] + 1) {
            throw new InvalidArgumentException('Année académique absente ou mal formée dans le fichier (attendu : 2026-2027).');
        }
        $parcoursListe = $document['parcours'] ?? null;
        if (! is_array($parcoursListe) || $parcoursListe === []) {
            throw new InvalidArgumentException('Le fichier ne contient aucun parcours.');
        }

        $bilan = ['annee' => $annee, 'creees' => [], 'remplacees' => [], 'ignorees' => [], 'ecarts' => []];

        $executer = function () use ($document, $parcoursListe, $annee, $appliquer, $auteur, &$bilan): void {
            $regle = $appliquer ? RegleEvaluation::pourAnnee($annee) : new RegleEvaluation(RegleEvaluation::valeursParDefaut($annee));
            foreach (array_values($parcoursListe) as $ordre => $p) {
                $this->importerParcours($p, $ordre, $annee, $regle, (string) ($document['source'] ?? ''), $appliquer, $auteur, $bilan);
            }
        };

        $appliquer ? DB::transaction($executer) : $executer();

        return $bilan;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array{annee: string, creees: list<string>, remplacees: list<string>, ignorees: array<string, string>, ecarts: list<string>}  $bilan
     */
    private function importerParcours(array $p, int $ordre, string $annee, RegleEvaluation $regle, string $source, bool $appliquer, ?User $auteur, array &$bilan): void
    {
        $code = strtoupper(trim((string) ($p['code'] ?? '')));
        $niveau = (string) ($p['niveau'] ?? '');
        if ($code === '' || ! in_array($niveau, ['M1', 'M2'], true) || ! is_array($p['ues'] ?? null)) {
            throw new InvalidArgumentException("Parcours mal formé dans le fichier : « {$code} ».");
        }

        $parcours = Parcours::query()->where('code', $code)->first();
        if ($parcours !== null && $parcours->libelle !== (string) $p['libelle']) {
            $bilan['ecarts'][] = "Parcours {$code} : libellé conservé « {$parcours->libelle} » (fichier : « {$p['libelle']} »).";
        }
        $maquette = $parcours ? Maquette::query()->where('annee_academique', $annee)->where('parcours_id', $parcours->id)->first() : null;

        if ($maquette !== null && ! $maquette->estModifiable()) {
            $bilan['ignorees'][$code] = 'maquette déjà validée';

            return;
        }
        if ($maquette !== null && $this->aDesInscrits($maquette)) {
            $bilan['ignorees'][$code] = 'des auditeurs sont inscrits sur ce brouillon';

            return;
        }

        $bilan[$maquette === null ? 'creees' : 'remplacees'][] = $code;
        if (! $appliquer) {
            foreach ($p['ues'] as $u) {
                $this->noterEcartUe($u, $bilan);
            }

            return;
        }

        $parcours ??= Parcours::query()->create([
            'code' => $code, 'libelle' => (string) $p['libelle'], 'niveau' => $niveau,
            'specialite' => $p['specialite'] ?? null, 'actif' => true, 'ordre' => $ordre,
        ]);
        if ($maquette === null) {
            $maquette = Maquette::query()->create(['annee_academique' => $annee, 'parcours_id' => $parcours->id, 'source' => mb_substr($source, 0, 500)]);
        } else {
            $this->viderBrouillon($maquette);
            $maquette->update(['source' => mb_substr($source, 0, 500)]);
        }

        foreach (array_values($p['ues']) as $i => $u) {
            $this->noterEcartUe($u, $bilan);
            $ue = UniteEnseignement::query()->firstOrCreate(['code' => (string) $u['code']], ['intitule' => (string) $u['intitule']]);
            $ligne = MaquetteUe::query()->create([
                'maquette_id' => $maquette->id,
                'unite_enseignement_id' => $ue->id,
                'semestre' => (int) $u['semestre'],
                'credits' => (int) $u['credits'],
                'pcc' => $regle->pcc_defaut,
                'ptp' => $regle->ptp_defaut,
                'pexam' => $regle->pexam_defaut,
                'partie' => ($u['partie'] ?? MaquetteUe::ECRITE) === MaquetteUe::SOUTENANCE ? MaquetteUe::SOUTENANCE : MaquetteUe::ECRITE,
                'ordre' => $i,
            ]);
            foreach (array_values($u['ecues'] ?? []) as $j => $e) {
                $ligne->ecues()->create([
                    'code' => (string) $e['code'],
                    'intitule' => mb_substr((string) $e['intitule'], 0, 500),
                    'credits' => (int) $e['credits'],
                    'heures_cm' => (int) ($e['cm'] ?? 0),
                    'heures_td' => (int) ($e['td'] ?? 0),
                    'heures_tp' => (int) ($e['tp'] ?? 0),
                    'heures_tpe' => (int) ($e['tpe'] ?? 0),
                    'ordre' => $j,
                ]);
            }
        }

        activity('lmd')->causedBy($auteur)->performedOn($maquette)->event('maquette_importee')
            ->withProperties(['annee' => $annee, 'parcours' => $code, 'ues' => count($p['ues'])])
            ->log('Maquette importée en brouillon');
    }

    /**
     * @param  array<string, mixed>  $u
     * @param  array{ecarts: list<string>}  $bilan
     */
    private function noterEcartUe(array $u, array &$bilan): void
    {
        $intitule = UniteEnseignement::query()->where('code', (string) $u['code'])->value('intitule');
        if ($intitule !== null && $intitule !== (string) $u['intitule']) {
            $bilan['ecarts'][] = "UE {$u['code']} : intitulé du catalogue conservé « {$intitule} » (fichier : « {$u['intitule']} »).";
        }
    }

    private function aDesInscrits(Maquette $maquette): bool
    {
        return InscriptionPedagogique::query()
            ->whereIn('maquette_ue_id', $maquette->ues()->select('id'))
            ->exists();
    }

    private function viderBrouillon(Maquette $maquette): void
    {
        $lignes = $maquette->ues()->pluck('id');
        MaquetteEcue::query()->whereIn('maquette_ue_id', $lignes)->delete();
        MaquetteUe::query()->whereIn('id', $lignes)->delete();
    }
}
