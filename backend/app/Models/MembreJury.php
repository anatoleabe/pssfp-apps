<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Membre d'un jury ; le compte rattaché signe le PV. */
class MembreJury extends Model
{
    public const ROLES = [
        'president' => 'Président',
        'vice_president' => 'Vice-président',
        'rapporteur' => 'Rapporteur',
        'membre' => 'Membre',
    ];

    protected $table = 'membres_jury';

    protected $fillable = ['jury_id', 'role', 'rang', 'titre', 'nom', 'user_id'];

    public function jury(): BelongsTo
    {
        return $this->belongsTo(Jury::class);
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelle(): string
    {
        return trim(($this->titre ? $this->titre.' ' : '').$this->nom).' ('.(self::ROLES[$this->role] ?? $this->role).')';
    }
}
