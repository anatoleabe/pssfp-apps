<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Auditeur du programme, à partir de l'attribution de son matricule.
 *
 * Ancrage de la vie académique (ADR-0010). Le compte, l'identité, le matricule
 * et le mot de passe FOAD se lisent à travers la candidature : aucune copie, car
 * `AjoutRetenu::corrigerTelephone` peut changer le compte d'un dossier.
 */
class Auditeur extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = ['candidature_id'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    public function inscriptions(): HasMany
    {
        return $this->hasMany(InscriptionAnnuelle::class);
    }

    /** Tous les versements, toutes années, annulés compris (historique de la fiche). */
    public function versements(): HasManyThrough
    {
        return $this->hasManyThrough(Versement::class, InscriptionAnnuelle::class);
    }

    /** Inscription de l'année académique la plus récente : celle qui commande l'accès. */
    public function inscriptionCourante(): HasOne
    {
        return $this->hasOne(InscriptionAnnuelle::class)->ofMany(['annee_academique' => 'max']);
    }
}
