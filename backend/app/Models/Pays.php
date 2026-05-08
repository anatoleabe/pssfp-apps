<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pays extends Model
{
    protected $table = 'pays';

    protected $primaryKey = 'code_iso';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code_iso', 'nom', 'indicatif'];
}
