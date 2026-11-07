<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\InscriptionPedagogique;
use App\Models\Maquette;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Validation et réouverture d'une maquette (permission lmd.maquette_valider).
 * Validée, une maquette ne se modifie plus ; elle ne se rouvre, avec un motif,
 * que tant qu'aucun auditeur n'y est inscrit.
 */
final class ValidationMaquette
{
    public function __construct(private readonly ControleMaquette $controle) {}

    /** @throws InvalidArgumentException contrôle bloquant ou statut incompatible */
    public function valider(Maquette $maquette, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::VALIDER_MAQUETTE), 403);

        DB::transaction(function () use ($maquette, $auteur): void {
            $maquette = Maquette::query()->lockForUpdate()->findOrFail($maquette->id);
            if ($maquette->statut !== Maquette::BROUILLON) {
                throw new InvalidArgumentException('Seul un brouillon se valide.');
            }
            $bloquantes = $this->controle->bloquantes($maquette);
            if ($bloquantes !== []) {
                throw new InvalidArgumentException('Validation impossible : '.implode(' ', array_map(fn (Anomalie $a): string => $a->message, $bloquantes)));
            }
            $maquette->forceFill(['statut' => Maquette::VALIDEE, 'validee_par' => $auteur->id, 'validee_at' => now()])->save();
            activity('lmd')->causedBy($auteur)->performedOn($maquette)->event('maquette_validee')
                ->withProperties(['annee' => $maquette->annee_academique, 'credits' => $maquette->totalCredits()])
                ->log('Maquette validée');
        });
    }

    /** @throws InvalidArgumentException motif absent, statut incompatible, auditeurs inscrits */
    public function rouvrir(Maquette $maquette, User $auteur, string $motif): void
    {
        abort_unless($auteur->can(PermissionsLmd::VALIDER_MAQUETTE), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < 10) {
            throw new InvalidArgumentException('Indiquez le motif de la réouverture (10 caractères au moins).');
        }

        DB::transaction(function () use ($maquette, $auteur, $motif): void {
            $maquette = Maquette::query()->lockForUpdate()->findOrFail($maquette->id);
            if ($maquette->statut !== Maquette::VALIDEE) {
                throw new InvalidArgumentException('Seule une maquette validée se rouvre.');
            }
            if (InscriptionPedagogique::query()->whereIn('maquette_ue_id', $maquette->ues()->select('id'))->exists()) {
                throw new InvalidArgumentException('Des auditeurs sont inscrits sur cette maquette : elle ne se rouvre plus.');
            }
            $maquette->forceFill(['statut' => Maquette::BROUILLON, 'validee_par' => null, 'validee_at' => null])->save();
            activity('lmd')->causedBy($auteur)->performedOn($maquette)->event('maquette_rouverte')
                ->withProperties(['motif' => $motif])
                ->log('Maquette rouverte en brouillon');
        });
    }
}
