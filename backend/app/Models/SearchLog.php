<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Requête de recherche publique, pour repérer les recherches sans résultat.
 *
 * Sans donnée personnelle (pas d'IP) ; purgée après 90 jours (spec V1 §5.1).
 */
class SearchLog extends Model
{
    use Prunable;

    public const RETENTION_DAYS = 90;

    public $timestamps = false;

    protected $fillable = ['query', 'filters', 'results_count', 'searched_at'];

    protected $casts = ['filters' => 'array', 'results_count' => 'integer', 'searched_at' => 'datetime'];

    public function prunable(): Builder
    {
        return static::query()->where('searched_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
