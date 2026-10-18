<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ancienne URL de la bibliothèque Joomla → nouveau chemin. */
class LibraryRedirect extends Model
{
    protected $fillable = ['from_path', 'to_path', 'status_code', 'hits'];

    protected $casts = ['status_code' => 'integer', 'hits' => 'integer'];
}
