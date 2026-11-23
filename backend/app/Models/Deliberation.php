<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Lmd\NumerotationSemestres;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Délibération d'une année, d'un niveau et d'une session (D35), sur l'année entière ou sur un
 * seul semestre (spec lot 17a : `semestre`, rang de 1 à 4 dans le master). Les états ne
 * changent que par App\Services\Lmd\Deliberations : simulation → arrêtée
 * (→ rectifiée quand sa rectification est arrêtée).
 */
class Deliberation extends Model
{
    use HasUuids;

    public const SIMULATION = 'simulation';

    public const ARRETEE = 'arretee';

    public const RECTIFIEE = 'rectifiee';

    public const STATUTS = [self::SIMULATION => 'En préparation', self::ARRETEE => 'Arrêtée', self::RECTIFIEE => 'Rectifiée'];

    /** Décisions d'une délibération de semestre : mêmes codes que l'année, autre sens. */
    public const DECISIONS_SEMESTRE = ['AD' => 'Semestre validé', 'AJ' => 'Semestre non validé'];

    /** Le semestre 4 porte la soutenance : il ne se délibère pas seul (spec lot 22). */
    public const SEMESTRE_SOUTENANCE = 4;

    protected $fillable = ['annee_academique', 'niveau', 'parcours_id', 'session', 'jury_id', 'date_deliberation', 'semestre'];

    protected $attributes = ['statut' => self::SIMULATION];

    protected $casts = [
        'semestre' => 'integer',
        'date_deliberation' => 'immutable_date',
        'seance_ouverte_at' => 'datetime',
        'quorum_atteint' => 'boolean',
        'arretee_at' => 'datetime',
        'publiee_at' => 'datetime',
        'requetes_jusqu_au' => 'immutable_date',
        'a_rectifier_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function jury(): BelongsTo
    {
        return $this->belongsTo(Jury::class);
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(Scenario::class)->orderBy('id');
    }

    public function scenarioRetenu(): HasOne
    {
        return $this->hasOne(Scenario::class)->where('retenu', true);
    }

    public function mesures(): HasManyThrough
    {
        return $this->hasManyThrough(MesureJury::class, Scenario::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ParticipationDeliberation::class);
    }

    public function resultats(): HasMany
    {
        return $this->hasMany(ResultatAnnuel::class);
    }

    public function rectifiee(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectifie_id');
    }

    public function rectification(): HasOne
    {
        return $this->hasOne(self::class, 'rectifie_id');
    }

    public function arreteePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'arretee_par');
    }

    public function estModifiable(): bool
    {
        return $this->statut === self::SIMULATION;
    }

    public function libelle(): string
    {
        return sprintf('%s · %s%s%s · %s', $this->annee_academique, $this->niveau,
            $this->parcours ? ' '.$this->parcours->code : '', $this->estSemestrielle() ? ' · '.$this->libelleSemestre() : '',
            EtapeSaisie::SESSIONS[$this->session] ?? $this->session);
    }

    public function estSemestrielle(): bool
    {
        return $this->semestre !== null;
    }

    /** « Semestre 1 », ou « Semestre 7 » selon la numérotation de la promotion ; null pour l'année. */
    public function libelleSemestre(): ?string
    {
        return $this->semestre === null ? null
            : NumerotationSemestres::libelle($this->semestre, NumerotationSemestres::promotion($this->annee_academique, $this->niveau));
    }

    /**
     * Semestres enregistrés dans les maquettes pour ce rang : 1 à 4, ou 7 à 10 en numérotation LMD.
     *
     * @return list<int>
     */
    public function semestresEnregistres(): array
    {
        return $this->semestre === null ? [] : [$this->semestre, $this->semestre + NumerotationSemestres::DECALAGE_LMD];
    }

    /**
     * Délibérations sur lesquelles celle-ci s'appuie (spec lot 17a, règle 4) : elle reprend leurs UE
     * acquises. L'année s'appuie sur ses semestres ; le rattrapage, sur la session normale de même portée.
     *
     * @return Builder<self>
     */
    public function appuis(): Builder
    {
        $annee = $this->semestre === null;
        $rattrapage = $this->session === EtapeSaisie::RATTRAPAGE;
        if (! $annee && ! $rattrapage) {
            return $this->voisines()->whereKey([]);
        }

        return $this->voisines()->where(function (Builder $q) use ($annee, $rattrapage): void {
            if ($annee) {
                $q->whereNotNull('semestre');
            }
            if ($rattrapage) {
                $q->orWhere(fn (Builder $q) => $q->where('session', EtapeSaisie::NORMALE)->where('semestre', $this->semestre));
            }
        });
    }

    /**
     * Délibérations qui s'appuient sur celle-ci : l'inverse de `appuis()`.
     *
     * @return Builder<self>
     */
    public function dependantes(): Builder
    {
        $semestre = $this->semestre !== null;
        $normale = $this->session === EtapeSaisie::NORMALE;
        if (! $semestre && ! $normale) {
            return $this->voisines()->whereKey([]);
        }

        return $this->voisines()->where(function (Builder $q) use ($semestre, $normale): void {
            if ($semestre) {
                $q->whereNull('semestre');
            }
            if ($normale) {
                $q->orWhere(fn (Builder $q) => $q->where('session', EtapeSaisie::RATTRAPAGE)->where('semestre', $this->semestre));
            }
        });
    }

    /** Autres délibérations de la même année et du même niveau, dont le parcours recoupe le sien. */
    private function voisines(): Builder
    {
        return self::query()->whereKeyNot($this->id)
            ->where('annee_academique', $this->annee_academique)->where('niveau', $this->niveau)
            ->when($this->parcours_id !== null, fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('parcours_id')->orWhere('parcours_id', $this->parcours_id)));
    }

    /**
     * Libellés des décisions selon la portée.
     *
     * @return array<string, string>
     */
    public function decisions(): array
    {
        return $this->estSemestrielle() ? self::DECISIONS_SEMESTRE : ResultatAnnuel::DECISIONS;
    }

    /** Délibérations annuelles seulement : elles seules décident du passage et de la soutenance. */
    public function scopeAnnuelles(Builder $query): Builder
    {
        return $query->whereNull('semestre');
    }
}
