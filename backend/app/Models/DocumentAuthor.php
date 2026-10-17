<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Auteur d'un document, nom littéral (pas de lien vers un compte en V1). */
class DocumentAuthor extends Model
{
    protected $fillable = ['author_name', 'author_affiliation', 'order'];

    protected $casts = ['order' => 'integer'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
