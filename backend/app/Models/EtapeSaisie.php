<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étape de saisie d'un ECUE pour une session (D11) : CC, assiduité, examen.
 * Seul SaisieNotes change son statut.
 */
class EtapeSaisie extends Model
{
    public const NORMALE = 'normale';

    public const RATTRAPAGE = 'rattrapage';

    public const SESSIONS = [self::NORMALE => 'Session normale', self::RATTRAPAGE => 'Rattrapage'];

    public const CC = 'cc';

    public const ASSIDUITE = 'assiduite';

    public const EXAMEN = 'examen';

    public const ETAPES = [self::CC => 'Contrôle continu', self::ASSIDUITE => 'Assiduité', self::EXAMEN => 'Examen'];

    public const FERMEE = 'fermee';

    public const OUVERTE = 'ouverte';

    public const VERROUILLEE = 'verrouillee';

    public const STATUTS = [self::FERMEE => 'Fermée', self::OUVERTE => 'Ouverte', self::VERROUILLEE => 'Verrouillée'];

    protected $table = 'etapes_saisie';

    protected $fillable = ['maquette_ecue_id', 'session', 'etape'];

    protected $attributes = ['statut' => self::FERMEE];

    protected $casts = [
        'ouverte_at' => 'datetime',
        'verrouillee_at' => 'datetime',
        'rouverte_at' => 'datetime',
    ];

    /** @return list<string> étapes d'une session : le rattrapage n'a que l'examen */
    public static function etapesDe(string $session): array
    {
        return $session === self::RATTRAPAGE ? [self::EXAMEN] : [self::CC, self::ASSIDUITE, self::EXAMEN];
    }

    public function ecue(): BelongsTo
    {
        return $this->belongsTo(MaquetteEcue::class, 'maquette_ecue_id');
    }

    public function verrouilleePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verrouillee_par');
    }
}
