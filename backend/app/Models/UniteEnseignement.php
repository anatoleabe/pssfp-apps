<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Catalogue des UE : une UE commune à plusieurs maquettes n'existe qu'une fois. */
class UniteEnseignement extends Model
{
    use HasFactory;

    protected $table = 'unites_enseignement';

    protected $fillable = ['code', 'intitule'];

    public function lignesMaquette(): HasMany
    {
        return $this->hasMany(MaquetteUe::class);
    }
}
