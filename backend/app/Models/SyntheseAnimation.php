<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Journaux;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Synthèse de l'animation en ligne d'un cours par un enseignant (D25) : brouillon → validée ou rejetée ;
 * payée une seule fois par une ligne d'état de paie.
 */
class SyntheseAnimation extends Model
{
    use HasUuids;
    use LogsActivity;

    public const BROUILLON = 'brouillon';

    public const VALIDE = 'valide';

    public const REJETE = 'rejete';

    public const STATUTS = [self::BROUILLON => 'Brouillon', self::VALIDE => 'Validée', self::REJETE => 'Rejetée'];

    public const SOURCES = ['saisie' => 'Saisie', 'export_whatsapp' => 'Export WhatsApp'];

    protected $table = 'syntheses_animation';

    protected $fillable = [];

    protected $attributes = ['statut' => self::BROUILLON, 'source' => 'saisie'];

    protected $casts = ['periode_du' => 'immutable_date', 'periode_au' => 'immutable_date', 'valide_at' => 'datetime', 'importe_at' => 'datetime'];

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
        return LogOptions::defaults()->useLogName(Journaux::PAIE)
            ->logOnly(['periode_du', 'periode_au', 'statut', 'motif_rejet', 'source', 'justificatif'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    public function ecue(): BelongsTo
    {
        return $this->belongsTo(MaquetteEcue::class, 'maquette_ecue_id');
    }

    public function programmation(): BelongsTo
    {
        return $this->belongsTo(ProgrammationEnseignant::class, 'programmation_id');
    }

    public function activites(): HasMany
    {
        return $this->hasMany(ActiviteAnimation::class, 'synthese_id')->orderBy('id');
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(LigneEtatPaie::class, 'ligne_paie_id');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    public function importePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'importe_par');
    }

    public function estPayee(): bool
    {
        return $this->ligne_paie_id !== null;
    }
}
