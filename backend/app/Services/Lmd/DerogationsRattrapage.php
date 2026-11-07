<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\DerogationRattrapage;
use App\Models\InscriptionPedagogique;
use App\Models\User;
use InvalidArgumentException;

/** Dérogation à l'éligibilité au rattrapage (D11) : autorité, référence et date de l'acte, motif. Une par UE. */
final class DerogationsRattrapage
{
    /** @param  array{accordee_par: string, reference_acte: string, date_acte: string, motif: string}  $acte */
    public function accorder(InscriptionPedagogique $inscription, array $acte, User $auteur): DerogationRattrapage
    {
        abort_unless($auteur->can(PermissionsLmd::DEROGATION_RATTRAPAGE), 403);
        if (DerogationRattrapage::query()->where('inscription_pedagogique_id', $inscription->id)->exists()) {
            throw new InvalidArgumentException('Une dérogation existe déjà pour cette UE.');
        }
        foreach (['accordee_par', 'reference_acte', 'date_acte', 'motif'] as $champ) {
            if (trim((string) ($acte[$champ] ?? '')) === '') {
                throw new InvalidArgumentException('Autorité, référence, date de l\'acte et motif sont obligatoires.');
            }
        }

        $derogation = DerogationRattrapage::query()->create([
            'inscription_pedagogique_id' => $inscription->id,
            'accordee_par' => mb_substr(trim($acte['accordee_par']), 0, 255),
            'reference_acte' => mb_substr(trim($acte['reference_acte']), 0, 120),
            'date_acte' => $acte['date_acte'],
            'motif' => mb_substr(trim($acte['motif']), 0, 500),
            'saisie_par' => $auteur->id,
        ]);
        activity('lmd')->causedBy($auteur)->performedOn($inscription->inscriptionAnnuelle)->event('derogation_rattrapage')
            ->withProperties(['ue' => $inscription->maquetteUe?->ue?->code, 'reference' => $derogation->reference_acte])
            ->log('Dérogation au rattrapage accordée');

        return $derogation;
    }
}
