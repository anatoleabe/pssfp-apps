<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Journal des téléchargements (statistiques Filament).
 *
 * IP et navigateur servent à repérer les abus ; purgés après 180 jours (spec V1 §5.1).
 * Le compteur `documents.downloads_count` n'est pas touché par la purge.
 */
class DocumentDownload extends Model
{
    use Prunable;

    public const RETENTION_DAYS = 180;

    public $timestamps = false;

    protected $fillable = ['document_id', 'user_id', 'ip', 'user_agent', 'downloaded_at'];

    protected $casts = ['downloaded_at' => 'datetime'];

    public function prunable(): Builder
    {
        return static::query()->where('downloaded_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
