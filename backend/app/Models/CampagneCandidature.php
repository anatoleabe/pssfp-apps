<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class CampagneCandidature extends Model
{
    use HasFactory;

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
     * URL publique du communiqué conjoint signé, ou null s'il n'a pas encore
     * été déposé par la direction depuis Filament.
     */
    public function communiqueUrl(): ?string
    {
        if (blank($this->communique_pdf_path)) {
            return null;
        }

        return Storage::disk(self::COMMUNIQUE_DISK)->url($this->communique_pdf_path);
    }
}
