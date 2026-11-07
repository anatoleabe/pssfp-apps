<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Copie d'une épreuve anonyme : numéro, correspondance chiffrée, constat (RG-050). */
class CopieAnonyme extends Model
{
    public const CONSTATS = [
        'attribuee' => 'Numéro attribué (non constatée)',
        'presente' => 'Présente',
        'absente' => 'Absente',
        'blanche' => 'Copie blanche (0)',
        'fraude' => 'Fraude signalée',
        'egaree' => 'Égarée',
        'egaree_traitee' => 'Égarée, traitée',
        'hors_liste' => 'Candidat hors liste',
        'ecartee' => 'Écartée (décision motivée)',
    ];

    protected $table = 'copies_anonymes';

    protected $guarded = ['id'];

    protected $hidden = ['correspondance'];

    public function epreuve(): BelongsTo
    {
        return $this->belongsTo(EpreuveAnonyme::class, 'epreuve_anonyme_id');
    }

    public function saisies(): HasMany
    {
        return $this->hasMany(SaisieAnonyme::class)->orderBy('correction')->orderBy('saisie');
    }
}
