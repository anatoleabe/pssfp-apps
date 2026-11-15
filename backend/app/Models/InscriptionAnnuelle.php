<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Scolarite\MessagesAuditeurs;
use App\Services\Scolarite\SituationFinanciere;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Inscription d'un auditeur pour une année académique et un niveau.
 *
 * Les montants sont copiés du barème et les champs sensibles (remise,
 * notification) ne sont jamais remplis en masse : seul RegistreVersements et
 * NotificationAcces les posent.
 */
class InscriptionAnnuelle extends Model
{
    use HasFactory;
    use HasUuids;

    public const FUSEAU = 'Africa/Douala';

    protected $table = 'inscriptions_annuelles';

    protected $fillable = [
        'auditeur_id',
        'bareme_id',
        'annee_academique',
        'niveau',
        'montant_inscription',
        'montant_tranche1',
        'montant_tranche2',
    ];

    protected $casts = [
        'montant_inscription' => 'integer',
        'montant_tranche1' => 'integer',
        'montant_tranche2' => 'integer',
        'remise_montant' => 'integer',
        'remise_par' => 'integer',
        'remise_at' => 'datetime',
        'acces_notifie_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function aujourdhui(): CarbonImmutable
    {
        return CarbonImmutable::now(self::FUSEAU)->startOfDay();
    }

    public function auditeur(): BelongsTo
    {
        return $this->belongsTo(Auditeur::class);
    }

    public function bareme(): BelongsTo
    {
        return $this->belongsTo(BaremeScolarite::class, 'bareme_id');
    }

    public function versements(): HasMany
    {
        return $this->hasMany(Versement::class);
    }

    /** Parcours LMD de l'inscription (lot 4) : seul InscriptionsPedagogiques le pose. */
    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }

    public function inscriptionsPedagogiques(): HasMany
    {
        return $this->hasMany(InscriptionPedagogique::class);
    }

    /** Versements non annulés qui désignent cette inscription (ceux que l'espace affiche). */
    public function versementsValides(): HasMany
    {
        return $this->hasMany(Versement::class)->whereNull('annule_at');
    }

    /** Parts de versements qui règlent cette inscription : leur somme est le cumul (ADR-0013). */
    public function imputationsActives(): HasMany
    {
        return $this->hasMany(ImputationVersement::class)->whereNull('annule_at');
    }

    public function remiseAccordeePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remise_par');
    }

    /** Accès anticipés (lot A1), clos compris. */
    public function deblocages(): HasMany
    {
        return $this->hasMany(DeblocageAcces::class);
    }

    /** Le déblocage en cours, s'il y en a un (au plus un, garanti par un index unique partiel). */
    public function deblocageEnCours(): HasOne
    {
        return $this->hasOne(DeblocageAcces::class)->where('statut', DeblocageAcces::EN_COURS);
    }

    /**
     * Le cumul est l'imputé de l'inscription, au plus son dû : l'excédent d'un versement
     * est une avance du compte (CompteAuditeur), et `tropPercu` vaut donc toujours 0.
     * Sur une liste, charger `withSum('imputationsActives', 'montant')` et le barème
     * évite une requête par ligne.
     */
    public function situation(): SituationFinanciere
    {
        $verse = array_key_exists('imputations_actives_sum_montant', $this->attributes)
            ? (int) $this->attributes['imputations_actives_sum_montant']
            : (int) $this->imputationsActives()->sum('montant');

        return SituationFinanciere::calculer(
            montantInscription: $this->montant_inscription,
            montantTranche1: $this->montant_tranche1,
            montantTranche2: $this->montant_tranche2,
            remise: (int) $this->remise_montant,
            verse: $verse,
            aujourdhui: self::aujourdhui(),
            echeanceTranche1: $this->bareme?->tranche1_echeance,
            echeanceTranche2: $this->bareme?->tranche2_echeance,
        );
    }

    /*
     * Filtres de la situation, équivalents en base de SituationFinanciere.
     * SQL brut autorisé par les ADR-0010 et 0013 : la situation n'étant pas stockée,
     * Eloquent ne l'exprime pas. Valeurs fixes ou liées, jamais de saisie.
     * Le cumul est l'imputé (ADR-0013) ; seul lui a changé avec le compte de l'auditeur.
     */
    private const VERSE = '(SELECT COALESCE(SUM(x.montant), 0) FROM imputations_versements x WHERE x.inscription_annuelle_id = inscriptions_annuelles.id AND x.annule_at IS NULL)';

    private const DU_TRANCHE2 = 'GREATEST(0, inscriptions_annuelles.montant_tranche2 - inscriptions_annuelles.remise_montant)';

    private const DU_TRANCHE1 = 'GREATEST(0, inscriptions_annuelles.montant_tranche1 - GREATEST(0, inscriptions_annuelles.remise_montant - inscriptions_annuelles.montant_tranche2))';

    private const DU_INSCRIPTION = 'GREATEST(0, inscriptions_annuelles.montant_inscription - GREATEST(0, inscriptions_annuelles.remise_montant - inscriptions_annuelles.montant_tranche1 - inscriptions_annuelles.montant_tranche2))';

    public function scopeInscriptionSoldee(Builder $query): Builder
    {
        return $query->whereRaw(self::VERSE.' >= '.self::DU_INSCRIPTION);
    }

    public function scopeInscriptionNonSoldee(Builder $query): Builder
    {
        return $query->whereRaw(self::VERSE.' < '.self::DU_INSCRIPTION);
    }

    /**
     * Équivalent en base de AccesEcampus::accesOuvert() : droits soldés, ou déblocage
     * en cours d'un auditeur ni exclu ni en abandon.
     */
    public function scopeAccesOuvert(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->inscriptionSoldee()->orWhere(fn (Builder $d) => $d->whereHas('deblocageEnCours')
            ->whereHas('auditeur', fn (Builder $a) => $a->whereNotIn('statut', MessagesAuditeurs::STATUTS_EXCLUS))));
    }

    public function scopeEntierementSoldee(Builder $query): Builder
    {
        return $query->whereRaw(self::VERSE.' >= '.self::DU_INSCRIPTION.' + '.self::DU_TRANCHE1.' + '.self::DU_TRANCHE2);
    }

    public function scopeEnRetard(Builder $query): Builder
    {
        $jusquTranche1 = self::DU_INSCRIPTION.' + '.self::DU_TRANCHE1;
        $jour = self::aujourdhui()->toDateString();

        return $query->whereRaw(
            'EXISTS (SELECT 1 FROM baremes_scolarite b WHERE b.id = inscriptions_annuelles.bareme_id AND ('
            .'(b.tranche1_echeance < ? AND '.self::DU_TRANCHE1.' > 0 AND '.self::VERSE.' < '.$jusquTranche1.')'
            .' OR (b.tranche2_echeance < ? AND '.self::DU_TRANCHE2.' > 0 AND '.self::VERSE.' < '.$jusquTranche1.' + '.self::DU_TRANCHE2.')'
            .'))',
            [$jour, $jour],
        );
    }
}
