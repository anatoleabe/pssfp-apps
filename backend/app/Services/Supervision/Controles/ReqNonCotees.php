<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\Requete;
use App\Services\Supervision\Controle;

/** Requêtes déposées depuis plus d'un jour et pas encore cotées (D30, REQ_NON_COTEES). */
final class ReqNonCotees implements Controle
{
    public function code(): string
    {
        return 'REQ_NON_COTEES';
    }

    public function libelle(): string
    {
        return 'Requêtes déposées non cotées depuis plus d\'un jour';
    }

    public function domaine(): string
    {
        return 'Requêtes';
    }

    public function criticite(): string
    {
        return self::MAJEUR;
    }

    public function delaiJours(): int
    {
        return 1;
    }

    public function cas(): array
    {
        $cas = [];
        $requetes = Requete::query()->where('statut', 'deposee')->where('created_at', '<', now()->subDay())->orderBy('created_at')->get(['numero', 'created_at']);
        foreach ($requetes as $r) {
            $cas[(string) $r->numero] = ['resume' => $r->numero.' déposée le '.$r->created_at?->setTimezone('Africa/Douala')->format('d/m/Y')];
        }

        return $cas;
    }
}
