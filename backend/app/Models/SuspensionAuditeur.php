<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Suspension d'un auditeur pour une année académique, sur sa demande écrite (spec module-4-scolarite-suspension).
 * Seul SuspensionsAuditeurs l'écrit ; la base refuse la suppression et toute modification de la demande.
 */
class SuspensionAuditeur extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'suspensions_auditeurs';

    protected $fillable = [];

    protected $casts = [
        'auditeur_id' => 'integer',
        'inscription_annuelle_id' => 'integer',
        'demande_du' => 'immutable_date',
        'effet_le' => 'immutable_date',
        'suspendu_par' => 'integer',
        'created_at' => 'datetime',
        'ecampus_suspendu_at' => 'datetime',
        'ecampus_rouvert_at' => 'datetime',
        'levee_at' => 'datetime',
        'levee_par' => 'integer',
        'reprise_meme_annee' => 'boolean',
    ];

    /** Le chemin privé de la demande ne sort jamais du back-office. */
    protected $hidden = ['demande_path'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Une suspension ne se supprime pas : levez-la.'));
    }

    public function enCours(): bool
    {
        return $this->levee_at === null;
    }

    public function auditeur(): BelongsTo
    {
        return $this->belongsTo(Auditeur::class);
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class, 'inscription_annuelle_id');
    }

    public function suspenduPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspendu_par');
    }

    public function leveePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'levee_par');
    }
}
