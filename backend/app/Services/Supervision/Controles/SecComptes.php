<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\User;
use App\Services\Supervision\Controle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Comptes du personnel sans connexion depuis 90 jours (D30, SEC_COMPTES), d'après le journal des connexions. */
final class SecComptes implements Controle
{
    public const JOURS = 90;

    public function code(): string
    {
        return 'SEC_COMPTES';
    }

    public function libelle(): string
    {
        return 'Comptes du personnel sans connexion depuis '.self::JOURS.' jours';
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
        return 15;
    }

    public function cas(): array
    {
        $seuil = now()->subDays(self::JOURS);
        $dernieres = DB::table('activity_log')->where('log_name', 'connexions')->where('event', 'connexion')
            ->where('causer_type', User::class)->groupBy('causer_id')->selectRaw('causer_id, max(created_at) as derniere')
            ->pluck('derniere', 'causer_id');
        $cas = [];
        $comptes = User::query()->whereHas('roles', fn ($q) => $q->whereNotIn('name', User::NON_PANEL_ROLES))
            ->where('created_at', '<', $seuil)->orderBy('name')->get(['id', 'name']);
        foreach ($comptes as $u) {
            $derniere = $dernieres[$u->id] ?? null;
            if ($derniere === null || $derniere < $seuil) {
                $cas[(string) $u->id] = ['resume' => $u->name.' : '.($derniere === null ? 'aucune connexion enregistrée'
                    : 'dernière connexion le '.CarbonImmutable::parse($derniere)->setTimezone('Africa/Douala')->format('d/m/Y')), 'compte' => $u->id];
            }
        }

        return $cas;
    }
}
