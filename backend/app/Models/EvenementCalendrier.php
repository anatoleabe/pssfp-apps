<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Événement du calendrier (D18, D21) : dates prévues, réelles et de référence ; statut d'exécution. */
class EvenementCalendrier extends Model
{
    use HasUuids;

    public const TYPES = [
        'phase' => 'Phase (regroupement du Gantt)',
        'publication_admis' => 'Publication des listes d\'admis',
        'inscriptions' => 'Inscriptions',
        'rentree' => 'Rentrée académique',
        'paiement' => 'Date limite de paiement',
        'ceremonie' => 'Cérémonie',
        'semestre' => 'Semestre',
        'periode_cours' => 'Période de programmation des cours',
        'saisie_cc' => 'Saisie du contrôle continu',
        'saisie_assiduite' => 'Clôture de l\'assiduité',
        'examens' => 'Session d\'examens',
        'deliberation' => 'Jury de délibération',
        'publication' => 'Publication des résultats',
        'reclamations' => 'Période des requêtes',
        'rattrapage' => 'Session de rattrapage',
        'deliberation_rattrapage' => 'Jury après rattrapage',
        'redaction_memoires' => 'Rédaction des mémoires',
        'reunion' => 'Réunion',
        'depot_memoires' => 'Dépôt des mémoires',
        'pre_soutenance' => 'Pré-soutenance',
        'soutenances' => 'Soutenances',
        'synthese_memoires' => 'Jury de synthèse des mémoires',
        'vacances' => 'Interruption, vacances',
        'activite_periacademique' => 'Activité péri-académique',
        'conference' => 'Conférence thématique',
        'appel_candidatures' => 'Appel à candidatures',
        'stages' => 'Stages et voyages d\'étude',
        'production_documents' => 'Production des relevés, attestations et diplômes',
        'autre' => 'Autre événement',
    ];

    /** Types qui tiennent lieu de jury (D20). */
    public const JURYS = ['deliberation', 'deliberation_rattrapage', 'synthese_memoires'];

    public const STATUTS = ['prevu' => 'Prévu', 'en_cours' => 'En cours', 'realise' => 'Réalisé', 'reporte' => 'Reporté', 'annule' => 'Annulé'];

    protected $table = 'evenements_calendrier';

    protected $fillable = ['parent_id', 'ordre', 'type', 'libelle', 'niveau', 'parcours_id', 'promotion', 'session', 'vague',
        'debut_prevu', 'fin_prevue', 'responsable_id'];

    protected $attributes = ['statut' => 'prevu', 'avancement_pct' => 0];

    protected $casts = [
        'debut_prevu' => 'immutable_date',
        'fin_prevue' => 'immutable_date',
        'debut_reel' => 'immutable_date',
        'fin_reelle' => 'immutable_date',
        'debut_signe' => 'immutable_date',
        'fin_signe' => 'immutable_date',
        'avancement_pct' => 'integer',
        'ordre' => 'integer',
        'promotion' => 'integer',
        'vague' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function calendrier(): BelongsTo
    {
        return $this->belongsTo(CalendrierAcademique::class, 'calendrier_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }

    public function modifications(): HasMany
    {
        return $this->hasMany(ModificationEvenement::class, 'evenement_id');
    }

    public function actes(): HasMany
    {
        return $this->hasMany(Acte::class, 'evenement_id');
    }

    public function estPhase(): bool
    {
        return $this->type === 'phase';
    }

    public function estJalon(): bool
    {
        return ! $this->estPhase() && $this->debut_prevu->equalTo($this->fin_prevue);
    }

    /** Échu et non déclaré réalisé (D21). */
    public function enRetard(): bool
    {
        return ! $this->estPhase() && in_array($this->statut, ['prevu', 'en_cours'], true)
            && $this->fin_prevue->lt(now('Africa/Douala')->startOfDay());
    }

    /** Écart en jours entre le début prévu et la référence signée. */
    public function glissement(): ?int
    {
        return $this->debut_signe === null ? null : (int) $this->debut_signe->diffInDays($this->debut_prevu, false);
    }
}
