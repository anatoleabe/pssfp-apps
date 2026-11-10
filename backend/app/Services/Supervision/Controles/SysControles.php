<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Services\Supervision\Controle;
use Illuminate\Support\Facades\DB;

/** Contrôles dont la dernière exécution a échoué (D30, SYS_CONTROLES). Exécuté en dernier par Supervision. */
final class SysControles implements Controle
{
    public const CODE = 'SYS_CONTROLES';

    public function code(): string
    {
        return self::CODE;
    }

    public function libelle(): string
    {
        return 'Contrôles en erreur d\'exécution';
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

    public function cas(): array
    {
        $dernieres = DB::table('executions_controles')->where('controle_code', '<>', self::CODE)
            ->whereIn('id', DB::table('executions_controles')->selectRaw('max(id)')->groupBy('controle_code'))
            ->whereNotNull('erreur')->get(['controle_code', 'erreur']);
        $cas = [];
        foreach ($dernieres as $e) {
            $cas[(string) $e->controle_code] = ['resume' => $e->controle_code.' : '.mb_substr((string) $e->erreur, 0, 200)];
        }

        return $cas;
    }
}
