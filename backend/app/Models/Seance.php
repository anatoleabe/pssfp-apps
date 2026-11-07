<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Séance d'un ECUE (D12) : prévue, tenue, annulée ou reportée ; seules les séances tenues comptent pour l'assiduité.
 * Programmée dans un emploi du temps (lot 12c, D21) : salle, plage, enseignant, nature.
 */
class Seance extends Model
{
    use HasUuids;

    public const MODALITES = ['presentiel' => 'Présentiel', 'distanciel' => 'Distanciel', 'hybride' => 'Hybride'];

    public const STATUTS = ['prevue' => 'Prévue', 'tenue' => 'Tenue', 'annulee' => 'Annulée', 'reportee' => 'Reportée'];

    public const NATURES = ['CM' => 'Cours magistral', 'TD' => 'Travaux dirigés', 'TP' => 'Travaux pratiques', 'evaluation' => 'Évaluation'];

    /** Statuts d'une séance qui occupe sa plage (contrôles, volumes). */
    public const ACTIVES = ['prevue', 'tenue'];

    protected $fillable = ['maquette_ecue_id', 'date_seance', 'debut', 'fin', 'modalite', 'salle', 'lien_reunion'];

    protected $attributes = ['statut' => 'prevue', 'modalite' => 'hybride'];

    protected $casts = ['date_seance' => 'immutable_date'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function ecue(): BelongsTo
    {
        return $this->belongsTo(MaquetteEcue::class, 'maquette_ecue_id');
    }

    public function emploiDuTemps(): BelongsTo
    {
        return $this->belongsTo(EmploiDuTemps::class, 'emploi_du_temps_id');
    }

    public function salleAffectee(): BelongsTo
    {
        return $this->belongsTo(Salle::class, 'salle_id');
    }

    public function plage(): BelongsTo
    {
        return $this->belongsTo(PlageHoraire::class, 'plage_horaire_id');
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    public function remplacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reportee_vers_id');
    }

    public function pointages(): HasMany
    {
        return $this->hasMany(Pointage::class);
    }

    /** Durée de la séance en minutes. */
    public function duree(): int
    {
        return (int) CarbonImmutable::parse($this->debut)->diffInMinutes(CarbonImmutable::parse($this->fin));
    }

    public function libelle(): string
    {
        return $this->ecue?->code.' — '.$this->date_seance?->format('d/m/Y').' '.substr((string) $this->debut, 0, 5).'-'.substr((string) $this->fin, 0, 5);
    }
}
