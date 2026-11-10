<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Demande d'autorisation (lot 14c, D31). Seul App\Services\Messagerie\Demandes écrit ici. */
class DemandeAutorisation extends Model
{
    use HasUuids;

    public const SOUMISE = 'soumise';

    public const VISA_ATTENDU = 'visa_attendu';

    public const REJETEE = 'rejetee';

    public const EXECUTEE = 'executee';

    public const ECHEC = 'echec_execution';

    public const RETIREE = 'retiree';

    public const STATUTS = [
        self::SOUMISE => 'Soumise', self::VISA_ATTENDU => 'Visa du contrôle interne attendu', self::REJETEE => 'Rejetée',
        self::EXECUTEE => 'Exécutée', self::ECHEC => 'Échec d\'exécution', self::RETIREE => 'Retirée',
    ];

    /** En attente d'une décision. */
    public const EN_ATTENTE = [self::SOUMISE, self::VISA_ATTENDU];

    public const MOTIF_MIN = 10;

    public $timestamps = false;

    protected $table = 'demandes_autorisation';

    protected $fillable = [];

    protected $casts = ['soumise_le' => 'immutable_datetime', 'echeance' => 'immutable_datetime', 'traitee_le' => 'immutable_datetime'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(DecisionDemande::class, 'demande_id');
    }

    public function enAttente(): bool
    {
        return in_array($this->statut, self::EN_ATTENTE, true);
    }
}
