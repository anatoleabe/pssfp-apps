<?php

declare(strict_types=1);

namespace App\Services\Acma;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Base ACMA du poste de l'USI (connexion `acma_source`), lue par le Query Builder : valeurs rendues en texte par le
 * serveur (requêtes émulées), lecture en flux sans tampon (309 580 versions de notes).
 */
final class SourceAcmaMysql implements SourceAcma
{
    public function __construct(private readonly string $connexion = 'acma_source') {}

    public function serveur(): array
    {
        $version = $this->db()->table('information_schema.tables')->limit(1)->value(DB::raw('VERSION()'));

        return ['version' => (string) $version, 'base' => (string) $this->db()->getDatabaseName()];
    }

    public function tables(): array
    {
        return $this->db()->table('information_schema.tables')
            ->where('table_schema', $this->db()->getDatabaseName())
            ->orderBy('table_name')->pluck('table_name')->map(fn ($t): string => (string) $t)->all();
    }

    public function colonnes(string $table): array
    {
        return $this->db()->table('information_schema.columns')
            ->where('table_schema', $this->db()->getDatabaseName())->where('table_name', $table)
            ->orderBy('ordinal_position')
            ->get(['column_name as nom', 'column_type as type', 'column_key as cle'])
            ->map(fn (object $c): array => ['nom' => (string) $c->nom, 'type' => (string) $c->type, 'cle' => $c->cle === 'PRI'])
            ->all();
    }

    public function lignes(string $table, array $colonnes, array $ordre): iterable
    {
        $requete = $this->db()->table($table)->select($colonnes);
        foreach ($ordre as $colonne) {
            $requete->orderBy($colonne);
        }
        foreach ($requete->cursor() as $ligne) {
            yield array_map(fn ($v): ?string => $v === null ? null : (string) $v, array_values((array) $ligne));
        }
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connexion);
    }
}
