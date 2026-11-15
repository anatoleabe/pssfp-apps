<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Confirmation d'un versement par la scolarité, sur présentation du reçu bancaire original (spec
 * module-4-scolarite-encaissement-confirmation §3.2). Une par versement, en ajout seul : seul
 * ConfirmationsVersements l'écrit, et la base refuse toute modification ou suppression. Seul l'argent
 * confirmé rend un auditeur « à jour ».
 */
class ConfirmationVersement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'confirmations_versements';

    protected $fillable = [];

    protected $casts = [
        'versement_id' => 'integer',
        'confirme_par' => 'integer',
        'confirme_at' => 'datetime',
        'attestation' => 'boolean',
        'reprise' => 'boolean',
    ];

    /** Le chemin privé du scan ne sort jamais du back-office. */
    protected $hidden = ['justificatif_path'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une confirmation ne se modifie pas : annulez le versement.'));
        static::deleting(fn () => throw new LogicException('Une confirmation ne se supprime pas.'));
    }

    public function versement(): BelongsTo
    {
        return $this->belongsTo(Versement::class);
    }

    public function confirmePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirme_par');
    }
}
