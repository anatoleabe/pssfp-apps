<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Candidature extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const STATUT_POSTULANT = 'postulant';

    public const STATUT_CANDIDAT = 'candidat';

    public const STATUT_ACCEPTE = 'accepte';

    public const STATUT_REFUSE = 'refuse';

    protected $fillable = [
        'campagne_id',
        'user_id',
        'phone_e164',
        'phone_country',
        'email',
        'civilite',
        'nom',
        'prenom',
        'epouse',
        'date_naissance',
        'lieu_naissance',
        'genre',
        'statut_matrimonial',
        'nationalite',
        'pays_origine',
        'pays_residence',
        'region',
        'departement',
        'adresse',
        'ville_residence',
        'indicatif1',
        'telephone1',
        'indicatif2',
        'telephone2',
        'specialite',
        'second_choix',
        'type_etude',
        'premiere_langue',
        'diplome_obtenu',
        'institut',
        'specialite_diplome',
        'annee_diplome',
        // Diplôme requis pour l'admission — distinct du diplôme le plus élevé.
        'diplome_requis',
        'annee_diplome_requis',
        'domaine_diplome_requis',
        'specialite_diplome_requis',
        'institut_diplome_requis',
        // Blocs répétables facultatifs (JSONB).
        'autres_diplomes',
        'formations_professionnelles',
        'statut_actuel',
        'fonction_actuelle',
        'employeur',
        'adresse_employeur',
        'tel_employeur',
        'engagement_nom',
        'moyen_connaissance',
        'moyen_connaissance_detail',
        'photo_path',
        'statut',
        'submitted_at',
        'depot_physique_at',
        'depot_physique_by',
        'depot_physique_observation',
        'reviewed_at',
        'decided_at',
        'withdrawn_at',
        'frais_paye',
        'mode_paiement',
        'reference_paiement',
        'date_paiement',
        'recipisse_pdf_path',
        'recipisse_hash_sha256',
    ];

    protected $casts = [
        'date_naissance' => 'date',
        'submitted_at' => 'datetime',
        'depot_physique_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'decided_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'date_paiement' => 'date',
        'frais_paye' => 'boolean',
        'annee_diplome' => 'integer',
        'annee_diplome_requis' => 'integer',
        'autres_diplomes' => 'array',
        'formations_professionnelles' => 'array',
        // Champ système : jamais dans $fillable, valorisé par le DEFAULT Postgres.
        'form_version' => 'integer',
    ];

    /** Le `id` reste BIGSERIAL ; HasUuids génère uniquement le champ `uuid`. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** Identifiant exposé dans les URLs publiques /c/{uuid}. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(CampagneCandidature::class, 'campagne_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paysNationalite(): BelongsTo
    {
        return $this->belongsTo(Pays::class, 'nationalite', 'code_iso');
    }

    public function regionRel(): BelongsTo
    {
        return $this->belongsTo(RegionCameroun::class, 'region', 'code');
    }

    public function departementRel(): BelongsTo
    {
        return $this->belongsTo(DepartementCameroun::class, 'departement', 'code');
    }

    /** Pièces justificatives optionnelles (diplôme, acte naissance, etc.). */
    public function documents(): HasMany
    {
        return $this->hasMany(CandidatureDocument::class);
    }

    /** Agent de la scolarité ayant réceptionné le dossier papier. */
    public function depotPhysiquePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'depot_physique_by');
    }

    public function scopeForCampagne(Builder $query, int $campagneId): Builder
    {
        return $query->where('campagne_id', $campagneId);
    }

    public function scopeByStatut(Builder $query, string $statut): Builder
    {
        return $query->where('statut', $statut);
    }

    /**
     * Dossiers effectivement soumis en ligne par le candidat.
     *
     * `postulant` = brouillon jamais soumis : il ne compte dans aucune
     * statistique de dépôt.
     */
    public function scopeSoumises(Builder $query): Builder
    {
        return $query->whereNotNull('submitted_at')
            ->where('statut', '!=', self::STATUT_POSTULANT);
    }

    /** Dossiers papier réceptionnés au bureau de la scolarité. */
    public function scopeDeposePhysiquement(Builder $query): Builder
    {
        return $query->whereNotNull('depot_physique_at');
    }

    /** Soumis en ligne mais dossier papier jamais présenté au guichet. */
    public function scopeEnAttenteDepotPhysique(Builder $query): Builder
    {
        return $query->soumises()->whereNull('depot_physique_at');
    }

    public function depotPhysiqueRecu(): bool
    {
        return $this->depot_physique_at !== null;
    }
}
