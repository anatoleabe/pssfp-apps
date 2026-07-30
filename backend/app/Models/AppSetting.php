<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\AppSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réglage applicatif clé/valeur éditable depuis Filament.
 *
 * Ne pas lire directement ce modèle depuis le code métier : passer par
 * {@see AppSettings} qui gère le cache et les valeurs par défaut.
 */
class AppSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
