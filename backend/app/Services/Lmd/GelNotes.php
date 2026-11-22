<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Deliberation;
use App\Models\EtapeSaisie;
use App\Models\MaquetteEcue;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Gel des notes après délibération (spec lot 16a, § 2.1). Une délibération arrêtée couvre les ECUE
 * des maquettes de son année, de son niveau et de son parcours ; celle du rattrapage couvre les deux
 * sessions. Le gel se lève tant qu'une rectification de cette délibération est en préparation.
 */
final class GelNotes
{
    /** Sessions de délibération qui gèlent les notes d'une session de saisie. */
    private const COUVERTURE = [
        EtapeSaisie::NORMALE => [EtapeSaisie::NORMALE, EtapeSaisie::RATTRAPAGE],
        EtapeSaisie::RATTRAPAGE => [EtapeSaisie::RATTRAPAGE],
    ];

    /** Délibération arrêtée qui gèle les notes de cet ECUE pour cette session, ou null. */
    public function deliberation(MaquetteEcue $ecue, string $session): ?Deliberation
    {
        $ecue->loadMissing('maquetteUe.maquette.parcours');
        $maquette = $ecue->maquetteUe?->maquette;
        if ($maquette === null || $maquette->parcours === null) {
            return null;
        }

        return self::gelantes()
            ->where('annee_academique', $maquette->annee_academique)
            ->where('niveau', $maquette->parcours->niveau)
            ->where(fn (Builder $q) => $q->whereNull('parcours_id')->orWhere('parcours_id', $maquette->parcours_id))
            ->whereIn('session', self::sessionsCouvrantes($session))
            ->orderBy('arretee_at')
            ->first();
    }

    /** @throws InvalidArgumentException notes gelées : le message désigne le PV et la voie de correction */
    public function exiger(MaquetteEcue $ecue, string $session): void
    {
        $d = $this->deliberation($ecue, $session);
        if ($d !== null) {
            throw new InvalidArgumentException(sprintf(
                'Notes gelées par la délibération %s arrêtée le %s : une correction passe par une requête ou par une rectification de cette délibération.',
                $d->numero_pv, $d->arretee_at?->timezone('Africa/Douala')->format('d/m/Y')));
        }
    }

    /** Sessions de délibération qui couvrent les notes de cette session de saisie. @return list<string> */
    public static function sessionsCouvrantes(string $sessionSaisie): array
    {
        return self::COUVERTURE[$sessionSaisie] ?? [];
    }

    /** Sessions de saisie gelées par une délibération de cette session. @return list<string> */
    public static function sessionsGelees(string $sessionDeliberation): array
    {
        return array_keys(array_filter(self::COUVERTURE, fn (array $s): bool => in_array($sessionDeliberation, $s, true)));
    }

    /** Délibérations arrêtées sans rectification en préparation. @return Builder<Deliberation> */
    public static function gelantes(): Builder
    {
        return Deliberation::query()->where('statut', Deliberation::ARRETEE)
            ->whereDoesntHave('rectification', fn (Builder $q) => $q->where('statut', Deliberation::SIMULATION));
    }
}
