<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Maquette;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Reconduction d'une maquette sur une autre année, en brouillon (origine « reconduction » du dossier PSSFP Système). */
final class ReconductionMaquette
{
    /** @throws InvalidArgumentException année mal formée ou maquette déjà présente */
    public function reconduire(Maquette $source, string $annee, User $auteur): Maquette
    {
        abort_unless($auteur->can(PermissionsLmd::REFERENTIEL), 403);
        if (preg_match('/^(\d{4})-(\d{4})$/', $annee, $m) !== 1 || (int) $m[2] !== (int) $m[1] + 1) {
            throw new InvalidArgumentException('Année académique mal formée (attendu : 2027-2028).');
        }
        if (Maquette::query()->where('annee_academique', $annee)->where('parcours_id', $source->parcours_id)->exists()) {
            throw new InvalidArgumentException("Ce parcours a déjà une maquette pour {$annee}.");
        }

        return DB::transaction(function () use ($source, $annee, $auteur): Maquette {
            $copie = Maquette::query()->create([
                'annee_academique' => $annee,
                'parcours_id' => $source->parcours_id,
                'source' => mb_substr("Reconduction de la maquette {$source->annee_academique}", 0, 500),
            ]);
            foreach ($source->ues()->with('ecues')->get() as $ligne) {
                $nouvelle = $copie->ues()->create($ligne->only(['unite_enseignement_id', 'semestre', 'credits', 'pcc', 'ptp', 'pexam', 'partie', 'ordre']));
                foreach ($ligne->ecues as $ecue) {
                    $nouvelle->ecues()->create($ecue->only(['code', 'intitule', 'credits', 'heures_cm', 'heures_td', 'heures_tp', 'heures_tpe', 'ordre']));
                }
            }
            activity('lmd')->causedBy($auteur)->performedOn($copie)->event('maquette_reconduite')
                ->withProperties(['source' => $source->annee_academique, 'annee' => $annee])
                ->log('Maquette reconduite en brouillon');

            return $copie;
        });
    }
}
