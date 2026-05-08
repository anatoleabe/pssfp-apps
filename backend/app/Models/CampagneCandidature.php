<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampagneCandidature extends Model
{
    use HasFactory;

    protected $table = 'campagnes_candidature';

    protected $fillable = [
        'slug',
        'prefix_numero',
        'nom',
        'promotion_numero',
        'opens_at',
        'closes_at',
        'results_at',
        'status',
        'max_voeux',
    ];

    protected $casts = [
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'results_at' => 'datetime',
        'promotion_numero' => 'integer',
        'max_voeux' => 'integer',
    ];

    public function candidatures(): HasMany
    {
        return $this->hasMany(Candidature::class, 'campagne_id');
    }

    /** Campagnes actuellement ouvertes (status open + dans la fenêtre temporelle). */
    public function scopeCurrentlyOpen(Builder $query): Builder
    {
        return $query->where('status', 'open')
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>=', now());
    }

    public function isCurrentlyOpen(): bool
    {
        return $this->status === 'open'
            && $this->opens_at?->isPast() === true
            && $this->closes_at?->isFuture() === true;
    }
}
