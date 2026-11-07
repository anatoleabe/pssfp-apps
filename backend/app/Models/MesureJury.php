<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mesure du jury (D37) : relèvement {plage_min, plage_max, cible}, repêchage
 * {credits_min, ecart_max, ue_max}, cas particulier {inscription_annuelle_id, maquette_ue_id, note}.
 */
class MesureJury extends Model
{
    public const RELEVEMENT = 'relevement';

    public const REPECHAGE = 'repechage';

    public const CAS_PARTICULIER = 'cas_particulier';

    public const TYPES = [
        self::RELEVEMENT => 'Relèvement',
        self::REPECHAGE => 'Repêchage',
        self::CAS_PARTICULIER => 'Cas particulier',
    ];

    protected $table = 'mesures_jury';

    protected $fillable = ['scenario_id', 'type', 'parametres', 'motif', 'ordre'];

    protected $casts = ['parametres' => 'array'];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    /** La mesure en clair, pour le PV. */
    public function enClair(): string
    {
        $p = $this->parametres;
        $n = fn ($v): string => number_format((float) $v, 2, ',', ' ');

        return match ($this->type) {
            self::RELEVEMENT => sprintf('Relèvement des moyennes d\'UE de %s à %s, portées à %s', $n($p['plage_min'] ?? 0), $n($p['plage_max'] ?? 0), $n($p['cible'] ?? 0)),
            self::REPECHAGE => sprintf('Repêchage des auditeurs ayant au moins %d crédits : UE à moins de %s point du seuil, %d au plus par auditeur',
                (int) ($p['credits_min'] ?? 0), $n($p['ecart_max'] ?? 0), (int) ($p['ue_max'] ?? 1)),
            default => 'Cas particulier : UE acquise par décision motivée du jury',
        }.' — motif : '.$this->motif;
    }
}
