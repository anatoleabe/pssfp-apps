<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Écriture d'annulation d'un reçu (numéro du reçu suivi de « -A ») : le reçu lui-même reste intact. */
class AnnulationRecu extends Model
{
    use HasUuids;

    protected $table = 'annulations_recus';

    protected $fillable = [];

    protected $casts = ['annule_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une écriture d\'annulation ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une écriture d\'annulation ne se supprime pas.'));
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function recu(): BelongsTo
    {
        return $this->belongsTo(RecuVersement::class, 'recu_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annule_par');
    }
}
