<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\RelanceAnomalie;
use App\Services\Supervision\Controle;

/** Relances restées sans destinataire, faute de titulaire du rôle, d'administrateur et de super administrateur (D31, SYS_DESTINATAIRES). */
final class SysDestinataires implements Controle
{
    public const JOURS = 30;

    public function code(): string
    {
        return 'SYS_DESTINATAIRES';
    }

    public function libelle(): string
    {
        return 'Relances sans destinataire';
    }

    public function domaine(): string
    {
        return 'Supervision';
    }

    public function criticite(): string
    {
        return self::MAJEUR;
    }

    public function delaiJours(): int
    {
        return 1;
    }

    public function responsable(): string
    {
        return 'admin';
    }

    public function cas(): array
    {
        $cas = [];
        $relances = RelanceAnomalie::query()->where('destinataires', 0)->where('cree_le', '>=', now()->subDays(self::JOURS))
            ->with('anomalie:id,controle_code')->orderBy('id')->get();
        foreach ($relances as $r) {
            $cas[(string) $r->id] = [
                'resume' => 'Relance de niveau '.$r->niveau.' (anomalie n° '.$r->anomalie_id.', '.$r->anomalie?->controle_code.') : aucun compte ne détient le rôle « '.$r->destinataire_role.' »',
                'relance' => $r->id,
            ];
        }

        return $cas;
    }
}
