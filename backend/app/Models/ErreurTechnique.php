<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Erreur technique enregistrée pour l'administration : panne de l'API, du
 * back-office, d'une tâche de fond, ou écran d'erreur affiché par le portail
 * apply. Purgée après 90 jours.
 *
 * @property int $id
 * @property string $source
 * @property string|null $type
 * @property string $message
 * @property string|null $emplacement
 * @property string|null $methode
 * @property string|null $page
 * @property string|null $digest
 * @property string|null $requete
 * @property string|null $navigateur
 * @property string|null $ip
 * @property int|null $user_id
 * @property Carbon $created_at
 */
class ErreurTechnique extends Model
{
    use Prunable;

    public const SOURCE_API = 'api';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_TACHES = 'taches';

    public const SOURCE_APPLY = 'apply';

    public const SOURCES = [
        self::SOURCE_APPLY => 'Portail apply',
        self::SOURCE_API => 'API',
        self::SOURCE_ADMIN => 'Back-office',
        self::SOURCE_TACHES => 'Tâches de fond',
    ];

    public const RETENTION_JOURS = 90;

    public const UPDATED_AT = null;

    protected $table = 'erreurs_techniques';

    protected $fillable = [
        'source', 'type', 'message', 'emplacement', 'methode', 'page',
        'digest', 'requete', 'navigateur', 'ip', 'user_id', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_JOURS));
    }

    /** « Android · Chrome 151 » : l'appareil lisible à partir de l'en-tête du navigateur. */
    public static function appareil(?string $navigateur): string
    {
        if ($navigateur === null || $navigateur === '') {
            return '—';
        }
        $systeme = match (true) {
            str_contains($navigateur, 'Android') => 'Android',
            str_contains($navigateur, 'iPhone') => 'iPhone',
            str_contains($navigateur, 'iPad') => 'iPad',
            str_contains($navigateur, 'Windows') => 'Windows',
            str_contains($navigateur, 'Mac OS X') => 'Mac',
            str_contains($navigateur, 'Linux') => 'Linux',
            default => 'Autre',
        };
        $client = match (true) {
            preg_match('#Edg/(\d+)#', $navigateur, $m) === 1 => 'Edge '.$m[1],
            preg_match('#OPR/(\d+)#', $navigateur, $m) === 1 => 'Opera '.$m[1],
            preg_match('#SamsungBrowser/(\d+)#', $navigateur, $m) === 1 => 'Samsung Internet '.$m[1],
            preg_match('#(?:Firefox|FxiOS)/(\d+)#', $navigateur, $m) === 1 => 'Firefox '.$m[1],
            preg_match('#(?:Chrome|CriOS)/(\d+)#', $navigateur, $m) === 1 => 'Chrome '.$m[1],
            preg_match('#Version/(\d+).*Safari#', $navigateur, $m) === 1 => 'Safari '.$m[1],
            default => null,
        };

        return $client === null ? $systeme : $systeme.' · '.$client;
    }
}
