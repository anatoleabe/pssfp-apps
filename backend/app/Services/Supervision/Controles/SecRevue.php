<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Services\Audit\RevueHabilitations;
use App\Services\Supervision\Controle;
use App\Services\Supervision\Supervision;

/** Habilitations du personnel non revues depuis un an (D30, SEC_REVUE). */
final class SecRevue implements Controle
{
    public function __construct(private readonly RevueHabilitations $revue) {}

    public function code(): string
    {
        return 'SEC_REVUE';
    }

    public function libelle(): string
    {
        return 'Habilitations non revues depuis un an';
    }

    public function domaine(): string
    {
        return 'Sécurité';
    }

    public function criticite(): string
    {
        return self::MINEUR;
    }

    public function delaiJours(): int
    {
        return 30;
    }

    public function responsable(): string
    {
        return 'admin';
    }

    public function cas(): array
    {
        $cas = [];
        foreach ($this->revue->aRevoir() as $h) {
            $cas[$h['compte']->id.':'.$h['role']] = [
                'resume' => $h['compte']->name.' — rôle « '.$h['role'].' » : '.($h['derniere'] === null
                    ? 'jamais revu' : 'dernière revue le '.$h['derniere']->setTimezone(Supervision::FUSEAU)->format('d/m/Y')),
                'compte' => $h['compte']->id,
            ];
        }

        return $cas;
    }
}
