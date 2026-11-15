<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Engagement de prise en charge d'un organisme (spec module 4, lot A2 §9) : sa lettre
 * ouvre l'accès des auditeurs qu'on y rattache (déblocages `prise_en_charge`), sans
 * qu'un franc soit tenu pour payé ; l'argent arrive par ses règlements. Seul
 * EngagementsPriseEnCharge l'écrit ; jamais supprimé.
 */
class EngagementPriseEnCharge extends Model
{
    use HasUuids;

    public const DROITS_INSCRIPTION = 'droits_inscription';

    public const SCOLARITE_ANNEE = 'scolarite_annee';

    public const PORTEES = [
        self::DROITS_INSCRIPTION => 'Droits d\'inscription',
        self::SCOLARITE_ANNEE => 'Scolarité de l\'année',
    ];

    /** États calculés (§9.6), jamais stockés. */
    public const SANS_AUDITEUR = 'sans_auditeur';

    public const EN_COURS = 'en_cours';

    public const ECHU = 'echu';

    public const SOLDE = 'solde';

    public const ETATS = [
        self::SANS_AUDITEUR => 'Sans auditeur',
        self::EN_COURS => 'En cours',
        self::ECHU => 'Échu',
        self::SOLDE => 'Soldé',
    ];

    protected $table = 'engagements_prise_en_charge';

    protected $casts = [
        'lettre_date' => 'immutable_date',
        'echeance' => 'immutable_date',
        'cree_par' => 'integer',
        'prolongations' => 'integer',
        'relances' => 'integer',
        'derniere_relance_le' => 'datetime',
    ];

    /** Le chemin privé de la lettre ne sort jamais du back-office. */
    protected $hidden = ['lettre_path'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Déblocages qui rattachent des auditeurs, révoqués compris. */
    public function deblocages(): HasMany
    {
        return $this->hasMany(DeblocageAcces::class, 'engagement_id');
    }

    /** Liens qui couvrent une inscription : en cours ou régularisés, jamais révoqués. */
    public function deblocagesCouverts(): HasMany
    {
        return $this->deblocages()->where('statut', '<>', DeblocageAcces::REVOQUE);
    }

    public function reglements(): HasMany
    {
        return $this->hasMany(ReglementOrganisme::class, 'engagement_id');
    }

    public function reglementsValides(): HasMany
    {
        return $this->reglements()->whereNull('annule_at');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    /** Préchargements du suivi (§9.6) : inscriptions couvertes avec leur imputé, et argent reçu. Pas de requête par ligne. */
    public function scopeAvecSuivi(Builder $query): Builder
    {
        return $query->with(self::chargementSuivi())->withSum('reglementsValides', 'montant');
    }

    /** Recharge le suivi après un geste (rattachement, règlement). */
    public function rechargerSuivi(): static
    {
        return $this->load(self::chargementSuivi())->loadSum('reglementsValides', 'montant');
    }

    /** @return array<string, \Closure> */
    private static function chargementSuivi(): array
    {
        return ['deblocagesCouverts.inscription' => fn ($q) => $q->with('bareme')->withSum('imputationsActives', 'montant')];
    }

    public function estEchu(): bool
    {
        // Dates comparées en texte : l'échéance est un jour, sans fuseau.
        return $this->echeance->toDateString() < InscriptionAnnuelle::aujourdhui()->toDateString();
    }

    public function libellePortee(): string
    {
        return self::PORTEES[$this->portee] ?? $this->portee;
    }

    /** « LT-2026-001 du 10/11/2026 ». */
    public function libelleLettre(): string
    {
        return $this->lettre_reference.' du '.$this->lettre_date->format('d/m/Y');
    }
}
