<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lien court vers la lettre d'accès FOAD d'un auditeur (SMS). Le jeton n'est
 * jamais stocké : seule son empreinte SHA-256 l'est.
 *
 * @property int $id
 * @property int $candidature_id
 * @property string $empreinte
 * @property Carbon $expire_le
 * @property int $telechargements
 * @property int $echecs
 * @property Carbon|null $revoque_le
 * @property int|null $cree_par
 */
class LienLettreAcces extends Model
{
    public const DUREE_JOURS = 7;

    public const TELECHARGEMENTS_MAX = 5;

    public const ECHECS_MAX = 5;

    protected $table = 'liens_lettre_acces';

    protected $fillable = ['candidature_id', 'empreinte', 'expire_le', 'telechargements', 'echecs', 'revoque_le', 'cree_par'];

    protected $casts = [
        'expire_le' => 'datetime',
        'revoque_le' => 'datetime',
        'telechargements' => 'integer',
        'echecs' => 'integer',
    ];

    /** @return BelongsTo<Candidature, $this> */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    /** Ni révoqué, ni expiré, ni épuisé, ni verrouillé (la remise elle-même se vérifie à part). */
    public function utilisable(): bool
    {
        return $this->revoque_le === null
            && $this->expire_le->isFuture()
            && $this->telechargements < self::TELECHARGEMENTS_MAX
            && $this->echecs < self::ECHECS_MAX;
    }
}
