<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\Deliberation;
use App\Models\NoteEcue;
use App\Services\Lmd\CalculScenario;
use App\Services\Lmd\GelNotes;
use App\Services\Supervision\Controle;
use Carbon\CarbonImmutable;

/**
 * Notes modifiées après l'arrêt d'une délibération (spec lot 16a, § 2.3, NOT_APRES_ARRET) : filet de
 * détection du gel. Sont écartées les deux voies autorisées : la requête appliquée (délibération déjà
 * marquée « à rectifier ») et la rectification en préparation. Désignation par numéro de PV, sans nom.
 */
final class NotApresArret implements Controle
{
    public function code(): string
    {
        return 'NOT_APRES_ARRET';
    }

    public function libelle(): string
    {
        return 'Notes modifiées après l\'arrêt d\'une délibération, sans requête ni rectification';
    }

    public function domaine(): string
    {
        return 'Scolarité';
    }

    public function criticite(): string
    {
        return self::BLOQUANT;
    }

    public function delaiJours(): int
    {
        return 2;
    }

    public function responsable(): string
    {
        return 'scolarite_responsable';
    }

    public function cas(): array
    {
        $cas = [];
        foreach (GelNotes::gelantes()->whereNull('a_rectifier_at')->whereNotNull('arretee_at')->orderBy('id')->get() as $d) {
            // Lot 17a : une délibération qui reprend celle-ci est en rectification (gel levé), ou a été
            // arrêtée depuis : les notes changées avant son arrêt y sont prises en compte.
            if (GelNotes::leveeParDependante($d)) {
                continue;
            }
            $reprise = $d->dependantes()->where('statut', Deliberation::ARRETEE)->max('arretee_at');
            $seuil = $reprise !== null && CarbonImmutable::parse($reprise)->gt($d->arretee_at) ? CarbonImmutable::parse($reprise) : $d->arretee_at;
            $notes = NoteEcue::query()
                ->whereIn('session', GelNotes::sessionsGelees((string) $d->session))
                ->whereIn('inscription_pedagogique_id', CalculScenario::inscriptionsPedagogiques($d)->select('id'))
                ->where('updated_at', '>', $seuil)
                ->count();
            if ($notes === 0) {
                continue;
            }
            $cas['deliberation:'.$d->id] = [
                'resume' => sprintf('%s : %d note%s modifiée%s après l\'arrêt du %s, sans requête ni rectification',
                    $d->numero_pv, $notes, $notes > 1 ? 's' : '', $notes > 1 ? 's' : '', $d->arretee_at->timezone('Africa/Douala')->format('d/m/Y')),
                'deliberation' => $d->id,
                'notes' => $notes,
            ];
        }

        return $cas;
    }
}
