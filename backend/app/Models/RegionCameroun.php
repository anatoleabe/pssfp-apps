<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegionCameroun extends Model
{
    protected $table = 'regions_cameroun';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'nom', 'quota_admission', 'chef_lieu', 'order'];

    protected $casts = [
        'quota_admission' => 'decimal:4',
        'order' => 'integer',
    ];

    public function departements(): HasMany
    {
        return $this->hasMany(DepartementCameroun::class, 'region_code', 'code');
    }
}
