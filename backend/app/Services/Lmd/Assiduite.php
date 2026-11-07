<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\AttributionEcue;
use App\Models\EtapeSaisie;
use App\Models\InscriptionPedagogique;
use App\Models\MaquetteEcue;
use App\Models\Pointage;
use App\Models\RegleAssiduite;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Assiduité (spec lot 10 ; D12, D13) : séances, pointage, import Zoom, note d'assiduité
 * = 20 × (présences + absences justifiées) ÷ séances tenues, reportée dans les notes (lot 5).
 */
final class Assiduite
{
    public function __construct(private readonly SaisieNotes $saisie) {}

    /** L'auteur gère-t-il les séances de cet ECUE ? Scolarité, ou enseignant attributaire. */
    public function peutGerer(MaquetteEcue $ecue, User $user): bool
    {
        return $user->can(PermissionsLmd::ASSIDUITE)
            || ($user->can(PermissionsLmd::NOTES_ENSEIGNANT) && AttributionEcue::query()->where('maquette_ecue_id', $ecue->id)->where('user_id', $user->id)->exists());
    }

    /** @param  array{date_seance: string, debut: string, fin: string, modalite?: string, salle?: ?string, lien_reunion?: ?string}  $data */
    public function planifier(MaquetteEcue $ecue, array $data, User $auteur): Seance
    {
        abort_unless($this->peutGerer($ecue, $auteur), 403);
        if (strcmp((string) $data['fin'], (string) $data['debut']) <= 0) {
            throw new InvalidArgumentException('L\'heure de fin doit suivre l\'heure de début.');
        }
        $seance = Seance::query()->create([...$data, 'maquette_ecue_id' => $ecue->id]);
        $seance->forceFill(['cree_par' => $auteur->id])->save();

        return $seance;
    }

    /**
     * Pointage de la feuille de présence : les présents, les absences justifiées (motif commun), les autres absents.
     *
     * @param  list<int>  $presents  ids d'inscription pédagogique
     * @param  list<int>  $justifies
     */
    public function pointer(Seance $seance, array $presents, array $justifies, ?string $motif, User $auteur, string $mode = 'manuel'): int
    {
        $seance->loadMissing('ecue');
        abort_unless($this->peutGerer($seance->ecue, $auteur), 403);
        $this->modifiable($seance);
        if ($justifies !== [] && mb_strlen(trim((string) $motif)) < 5) {
            throw new InvalidArgumentException('Indiquez le motif des absences justifiées.');
        }

        return DB::transaction(function () use ($seance, $presents, $justifies, $motif, $auteur, $mode): int {
            $n = 0;
            foreach ($this->saisie->inscrits($seance->ecue) as $ip) {
                $statut = in_array($ip->id, $presents, true) ? 'present' : (in_array($ip->id, $justifies, true) ? 'justifie' : 'absent');
                Pointage::query()->updateOrCreate(['seance_id' => $seance->id, 'inscription_pedagogique_id' => $ip->id], [
                    'statut' => $statut, 'mode' => $mode, 'motif' => $statut === 'justifie' ? mb_substr(trim((string) $motif), 0, 500) : null,
                    'saisi_par' => $auteur->id,
                ]);
                $n++;
            }
            if ($seance->statut === 'prevue') {
                $seance->forceFill(['statut' => 'tenue'])->save();
            }

            return $n;
        });
    }

    public function annuler(Seance $seance, string $motif, User $auteur): void
    {
        $seance->loadMissing('ecue');
        abort_unless($this->peutGerer($seance->ecue, $auteur), 403);
        $this->modifiable($seance);
        if (mb_strlen(trim($motif)) < 5) {
            throw new InvalidArgumentException('Indiquez le motif de l\'annulation.');
        }
        $seance->forceFill(['statut' => 'annulee', 'motif_annulation' => mb_substr(trim($motif), 0, 500)])->save();
    }

    /**
     * Import du rapport des participants Zoom (CSV) : les connexions d'un participant s'additionnent ;
     * reconnaissance par adresse électronique, puis par nom ; présent si la durée atteint le temps requis.
     *
     * @return array{presents: int, absents: int, non_reconnus: list<string>}
     */
    public function importerZoom(Seance $seance, string $chemin, User $auteur, bool $appliquer): array
    {
        $seance->loadMissing('ecue.maquetteUe.maquette');
        abort_unless($this->peutGerer($seance->ecue, $auteur), 403);
        $this->modifiable($seance);
        $lignes = $this->lireCsv($chemin);

        $inscrits = $this->saisie->inscrits($seance->ecue);
        $parEmail = [];
        $parNom = [];
        foreach ($inscrits as $ip) {
            $d = $ip->inscriptionAnnuelle?->auditeur?->candidature;
            if ($d?->email) {
                $parEmail[mb_strtolower(trim((string) $d->email))] = $ip->id;
            }
            $parNom[self::cle(trim($d?->prenom.' '.$d?->nom))] = $ip->id;
            $parNom[self::cle(trim($d?->nom.' '.$d?->prenom))] = $ip->id;
        }

        $minutes = [];
        $nonReconnus = [];
        foreach ($lignes as $l) {
            $id = $parEmail[mb_strtolower($l['email'])] ?? $parNom[self::cle($l['nom'])] ?? null;
            if ($id === null) {
                $nonReconnus[$l['nom'] !== '' ? $l['nom'] : $l['email']] = true;

                continue;
            }
            $minutes[$id] = ($minutes[$id] ?? 0) + $l['minutes'];
        }
        $requis = (int) ceil($seance->duree() * RegleAssiduite::pourAnnee($seance->ecue->maquetteUe->maquette->annee_academique)->temps_requis_pct / 100);
        $presents = array_keys(array_filter($minutes, fn (int $m): bool => $m >= $requis));

        if ($appliquer) {
            DB::transaction(function () use ($seance, $presents, $minutes, $auteur): void {
                $existants = Pointage::query()->where('seance_id', $seance->id)->where('statut', 'present')->pluck('inscription_pedagogique_id')->all();
                $justifies = Pointage::query()->where('seance_id', $seance->id)->where('statut', 'justifie')->get()->keyBy('inscription_pedagogique_id');
                foreach ($this->saisie->inscrits($seance->ecue) as $ip) {
                    if ($justifies->has($ip->id)) {
                        continue;
                    }
                    $present = in_array($ip->id, $presents, true) || in_array($ip->id, $existants, true);
                    Pointage::query()->updateOrCreate(['seance_id' => $seance->id, 'inscription_pedagogique_id' => $ip->id], [
                        'statut' => $present ? 'present' : 'absent', 'mode' => isset($minutes[$ip->id]) ? 'zoom' : 'manuel',
                        'duree_min' => $minutes[$ip->id] ?? null, 'motif' => null, 'saisi_par' => $auteur->id,
                    ]);
                }
                if ($seance->statut === 'prevue') {
                    $seance->forceFill(['statut' => 'tenue'])->save();
                }
            });
        }

        return ['presents' => count($presents), 'absents' => $inscrits->count() - count($presents), 'non_reconnus' => array_keys($nonReconnus)];
    }

    /**
     * Assiduité d'une inscription pour un ECUE : séances tenues, présences, justifiées, note (null sans séance tenue).
     *
     * @return array{tenues: int, presents: int, justifies: int, note: ?float}
     */
    public function bilan(InscriptionPedagogique $ip, MaquetteEcue $ecue, RegleAssiduite $regle): array
    {
        $tenues = Seance::query()->where('maquette_ecue_id', $ecue->id)->where('statut', 'tenue')->pluck('id');
        $pointages = Pointage::query()->whereIn('seance_id', $tenues)->where('inscription_pedagogique_id', $ip->id)->pluck('statut');
        $presents = $pointages->filter(fn (string $s): bool => $s === 'present')->count();
        $justifies = $pointages->filter(fn (string $s): bool => $s === 'justifie')->count();
        $comptes = $presents + ($regle->justifiee_comptee ? $justifies : 0);

        return [
            'tenues' => $tenues->count(),
            'presents' => $presents,
            'justifies' => $justifies,
            'note' => $tenues->isEmpty() ? null : round((float) $regle->note_max * $comptes / $tenues->count(), 2),
        ];
    }

    /** Report des notes d'assiduité calculées dans l'étape « assiduité » ouverte de l'ECUE (lot 5), avec historique. */
    public function reporter(MaquetteEcue $ecue, User $auteur): int
    {
        $ecue->loadMissing('maquetteUe.maquette');
        $etape = $this->saisie->etape($ecue, EtapeSaisie::NORMALE, EtapeSaisie::ASSIDUITE);
        $regle = RegleAssiduite::pourAnnee($ecue->maquetteUe->maquette->annee_academique);
        $lignes = [];
        foreach ($this->saisie->inscrits($ecue) as $ip) {
            $note = $this->bilan($ip, $ecue, $regle)['note'];
            if ($note !== null) {
                $lignes[$ip->id] = ['note' => number_format($note, 2, '.', ''), 'motif' => 'Report de l\'assiduité calculée'];
            }
        }
        if ($lignes === []) {
            throw new InvalidArgumentException('Aucune séance tenue pour cet ECUE : rien à reporter.');
        }

        return $this->saisie->saisir($etape, $lignes, $auteur);
    }

    private function modifiable(Seance $seance): void
    {
        if ($seance->statut === 'annulee') {
            throw new InvalidArgumentException('Séance annulée.');
        }
        $etape = $this->saisie->etape($seance->ecue, EtapeSaisie::NORMALE, EtapeSaisie::ASSIDUITE);
        if ($etape->statut === EtapeSaisie::VERROUILLEE) {
            throw new InvalidArgumentException('L\'étape « assiduité » de cet ECUE est verrouillée : le pointage ne se modifie plus.');
        }
    }

    /**
     * Rapport des participants Zoom : colonnes « Name (original name) » ou « Nom », « User Email » ou
     * « E-mail », « Duration (minutes) » ou « Durée (minutes) ».
     *
     * @return list<array{nom: string, email: string, minutes: int}>
     */
    private function lireCsv(string $chemin): array
    {
        $f = @fopen($chemin, 'rb');
        if ($f === false) {
            throw new InvalidArgumentException('Fichier illisible.');
        }
        $entetes = null;
        $lignes = [];
        while (($ligne = fgetcsv($f, 0, ',', '"', '\\')) !== false) {
            if ($ligne === [null]) {
                continue;
            }
            $cles = array_map(fn ($v): string => self::cle(preg_replace('/^\xEF\xBB\xBF/', '', (string) $v)), $ligne);
            if ($entetes === null) {
                $nom = $this->colonne($cles, ['name original name', 'name', 'nom', 'nom nom d origine', 'participant']);
                $duree = $this->colonne($cles, ['duration minutes', 'duree minutes', 'total duration minutes']);
                if ($nom !== null && $duree !== null) {
                    $entetes = ['nom' => $nom, 'email' => $this->colonne($cles, ['user email', 'email', 'e mail', 'adresse e mail']), 'duree' => $duree];
                }

                continue;
            }
            $lignes[] = [
                'nom' => trim((string) ($ligne[$entetes['nom']] ?? '')),
                'email' => trim((string) ($entetes['email'] === null ? '' : ($ligne[$entetes['email']] ?? ''))),
                'minutes' => (int) ($ligne[$entetes['duree']] ?? 0),
            ];
        }
        fclose($f);
        if ($entetes === null) {
            throw new InvalidArgumentException('Ce fichier n\'est pas un rapport des participants Zoom (colonnes nom et durée introuvables).');
        }

        return $lignes;
    }

    /** @param  list<string>  $cles */
    private function colonne(array $cles, array $noms): ?int
    {
        foreach ($noms as $n) {
            $i = array_search($n, $cles, true);
            if ($i !== false) {
                return (int) $i;
            }
        }

        return null;
    }

    private static function cle(string $texte): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($texte))));
    }
}
