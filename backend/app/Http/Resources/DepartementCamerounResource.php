<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DepartementCameroun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DepartementCameroun
 */
final class DepartementCamerounResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'nom' => $this->nom,
            'chef_lieu' => $this->chef_lieu,
            'region_code' => $this->region_code,
        ];
    }
}
