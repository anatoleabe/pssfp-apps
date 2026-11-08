<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Activité d'une synthèse d'animation : quantité attendue (fixée par la Scolarité) et réalisée. */
class ActiviteAnimation extends Model
{
    public const CONTRIBUTION = 'contribution';

    public const ANIMATION = 'animation';

    public const FAMILLES = [self::CONTRIBUTION => 'Contribution', self::ANIMATION => 'Animation'];

    /** Activités comptées dans l'export WhatsApp et proposées à la création d'une synthèse (D25). */
    public const DOCUMENTS = 'Documents partagés';

    public const JOURS = 'Jours d\'animation';

    public const MESSAGES = 'Messages aux auditeurs';

    public const PROPOSEES = [self::DOCUMENTS => self::CONTRIBUTION, self::JOURS => self::ANIMATION, self::MESSAGES => self::ANIMATION];

    protected $table = 'activites_animation';

    protected $fillable = [];

    protected $casts = ['attendu' => 'integer', 'realise' => 'integer'];

    public function synthese(): BelongsTo
    {
        return $this->belongsTo(SyntheseAnimation::class, 'synthese_id');
    }
}
