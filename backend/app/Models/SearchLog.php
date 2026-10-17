<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Requête de recherche publique, pour repérer les recherches sans résultat. */
class SearchLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['query', 'filters', 'results_count', 'ip', 'searched_at'];

    protected $casts = ['filters' => 'array', 'results_count' => 'integer', 'searched_at' => 'datetime'];
}
