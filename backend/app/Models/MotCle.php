<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Translatable\HasTranslations;

/** Entrée du thésaurus de la bibliothèque (hiérarchique). */
class MotCle extends Model
{
    use HasFactory;
    use HasTranslations;
    use LogsActivity;

    protected $table = 'mots_cles';

    protected $fillable = ['slug', 'label', 'parent_id'];

    public array $translatable = ['label'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_mot_cle');
    }

    /**
     * Identifiants du mot-clé et de tous ses descendants (parcours itératif, une requête).
     *
     * @return list<int>
     */
    public static function selfAndDescendantIds(int $id): array
    {
        return self::descendantsIndex()[$id] ?? [$id];
    }

    /**
     * Pour chaque mot-clé, la liste de lui-même et de ses descendants.
     * Un ensemble « visité » protège des cycles de parent_id.
     *
     * @return array<int, list<int>>
     */
    public static function descendantsIndex(): array
    {
        $childrenOf = [];
        $ids = [];
        foreach (self::query()->get(['id', 'parent_id']) as $row) {
            $ids[] = $row->id;
            if ($row->parent_id !== null) {
                $childrenOf[$row->parent_id][] = $row->id;
            }
        }

        $index = [];
        foreach ($ids as $root) {
            $visited = [$root => true];
            $queue = [$root];
            while ($queue !== []) {
                foreach ($childrenOf[array_shift($queue)] ?? [] as $child) {
                    if (! isset($visited[$child])) {
                        $visited[$child] = true;
                        $queue[] = $child;
                    }
                }
            }
            $index[$root] = array_keys($visited);
        }

        return $index;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['slug', 'label', 'parent_id'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
