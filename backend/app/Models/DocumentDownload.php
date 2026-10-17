<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Journal des téléchargements (statistiques Filament). */
class DocumentDownload extends Model
{
    public $timestamps = false;

    protected $fillable = ['document_id', 'user_id', 'ip', 'user_agent', 'downloaded_at'];

    protected $casts = ['downloaded_at' => 'datetime'];
}
