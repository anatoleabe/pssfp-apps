<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartementCameroun extends Model
{
    protected $table = 'departements_cameroun';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'nom', 'chef_lieu', 'region_code'];

    public function region(): BelongsTo
    {
        return $this->belongsTo(RegionCameroun::class, 'region_code', 'code');
    }
}
