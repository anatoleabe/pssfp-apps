<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Séance du jury des requêtes (D27, D28) : PV numéroté, une ligne par requête, clos puis validé. */
class SeanceRequetes extends Model
{
    use HasUuids;

    protected $table = 'seances_requetes';

    protected $fillable = ['jury_id', 'tenue_le'];

    protected $casts = [
        'tenue_le' => 'immutable_date',
        'close_at' => 'datetime',
        'validee_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function jury(): BelongsTo
    {
        return $this->belongsTo(Jury::class);
    }

    public function requetes(): HasMany
    {
        return $this->hasMany(Requete::class, 'seance_requetes_id')->orderBy('ordre_seance');
    }

    public function estClose(): bool
    {
        return $this->close_at !== null;
    }

    public function libelle(): string
    {
        return 'Séance du '.$this->tenue_le?->format('d/m/Y').($this->numero_pv ? ' — '.$this->numero_pv : '');
    }
}
