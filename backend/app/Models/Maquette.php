<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Maquette d'un parcours pour une année académique. Validée, elle ne se modifie
 * plus : seule ValidationMaquette change son statut.
 */
class Maquette extends Model
{
    use HasFactory;
    use HasUuids;
    use LogsActivity;

    public const BROUILLON = 'brouillon';

    public const VALIDEE = 'validee';

    public const ARCHIVEE = 'archivee';

    public const STATUTS = [
        self::BROUILLON => 'Brouillon',
        self::VALIDEE => 'Validée',
        self::ARCHIVEE => 'Archivée',
    ];

    protected $fillable = ['annee_academique', 'parcours_id', 'source'];

    protected $attributes = ['statut' => self::BROUILLON];

    protected $casts = [
        'validee_at' => 'datetime',
        'validee_par' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly(['annee_academique', 'parcours_id', 'statut', 'source'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }

    public function ues(): HasMany
    {
        return $this->hasMany(MaquetteUe::class)->orderBy('semestre')->orderBy('ordre')->orderBy('id');
    }

    public function ecues(): HasManyThrough
    {
        return $this->hasManyThrough(MaquetteEcue::class, MaquetteUe::class);
    }

    public function valideePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validee_par');
    }

    public function estModifiable(): bool
    {
        return $this->statut === self::BROUILLON;
    }

    public function scopeValidees(Builder $query): Builder
    {
        return $query->where('statut', self::VALIDEE);
    }

    /** Maquette de référence d'une année et d'un parcours (D35), validée seulement. */
    public static function reference(string $annee, int $parcoursId): ?self
    {
        return self::query()->validees()->where('annee_academique', $annee)->where('parcours_id', $parcoursId)->first();
    }

    public function totalCredits(): int
    {
        return (int) $this->ues->sum('credits');
    }

    public function libelle(): string
    {
        return $this->annee_academique.' · '.($this->parcours?->libelleComplet() ?? '');
    }
}
