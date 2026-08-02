<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

class CampagneCandidature extends Model
{
    use HasFactory;
    use HasTranslations;

    /**
     * Le nom est affiché en titre de page sur le portail candidat : il doit
     * exister dans la langue du visiteur (ADR-0006). Repli sur le français.
     *
     * @var array<int, string>
     */
    public array $translatable = ['nom'];

    /** Disk public MinIO hébergeant le communiqué conjoint signé. */
    public const COMMUNIQUE_DISK = 'minio_media';

    protected $table = 'campagnes_candidature';

    protected $fillable = [
        'slug',
        'prefix_numero',
        'nom',
        'promotion_numero',
        'opens_at',
        'closes_at',
        'results_at',
        'status',
        'max_voeux',
        'communique_pdf_path',
        'communique_pdf_path_en',
        'communique_reference',
        'communique_signed_at',
    ];

    protected $casts = [
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'results_at' => 'datetime',
        'communique_signed_at' => 'date',
        'promotion_numero' => 'integer',
        'max_voeux' => 'integer',
    ];

    public function candidatures(): HasMany
    {
        return $this->hasMany(Candidature::class, 'campagne_id');
    }

    /** Campagnes actuellement ouvertes (status open + dans la fenêtre temporelle). */
    public function scopeCurrentlyOpen(Builder $query): Builder
    {
        return $query->where('status', 'open')
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>=', now());
    }

    public function isCurrentlyOpen(): bool
    {
        return $this->status === 'open'
            && $this->opens_at?->isPast() === true
            && $this->closes_at?->isFuture() === true;
    }

    /**
     * URL publique du communiqué conjoint signé dans la locale demandée.
     *
     * Pas de repli automatique ici : l'appelant décide quoi faire d'un `null`.
     * L'API expose les deux URLs et le front choisit, ce qui lui permet de
     * signaler explicitement qu'il sert la version française à un visiteur
     * anglophone plutôt que de le laisser croire à une traduction.
     */
    public function communiqueUrl(string $locale = 'fr'): ?string
    {
        $path = $locale === 'en' ? $this->communique_pdf_path_en : $this->communique_pdf_path;

        if (blank($path)) {
            return null;
        }

        return Storage::disk(self::COMMUNIQUE_DISK)->url($path);
    }
}
