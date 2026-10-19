<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\MotCleHasChildrenException;
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

    protected static function booted(): void
    {
        // La FK parent_id est en nullOnDelete : sans ce garde-fou, supprimer un parent
        // transformerait silencieusement ses enfants en racines, par quelque chemin que ce soit.
        static::deleting(function (self $motCle): void {
            if ($motCle->children()->exists()) {
                throw new MotCleHasChildrenException("Déplacez ou supprimez d'abord ses sous-thèmes.");
            }
        });
    }

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
     * Slugs du mot-clé et de tous ses descendants ; vide si le slug est inconnu.
     *
     * @return list<string>
     */
    public static function selfAndDescendantSlugs(string $slug): array
    {
        $id = self::query()->where('slug', $slug)->value('id');
        if ($id === null) {
            return [];
        }

        return self::query()->whereIn('id', self::selfAndDescendantIds($id))->orderBy('id')->pluck('slug')->all();
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
