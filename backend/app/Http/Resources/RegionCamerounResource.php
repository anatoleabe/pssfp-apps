<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RegionCameroun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RegionCameroun
 */
final class RegionCamerounResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'nom' => $this->nom,
            'quota_admission' => $this->quota_admission !== null
                ? (float) $this->quota_admission
                : null,
            'chef_lieu' => $this->chef_lieu,
            'order' => $this->order,
        ];
    }
}
