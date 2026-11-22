<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\InscriptionPedagogique;
use App\Models\NoteEcue;
use App\Services\Lmd\CalculScenario;
use App\Services\Lmd\GelNotes;
use App\Services\Supervision\Controle;

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
            $notes = NoteEcue::query()
                ->whereIn('session', GelNotes::sessionsGelees((string) $d->session))
                ->whereIn('inscription_pedagogique_id', InscriptionPedagogique::query()
                    ->whereIn('inscription_annuelle_id', CalculScenario::population($d)->select('id'))->select('id'))
                ->where('updated_at', '>', $d->arretee_at)
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
