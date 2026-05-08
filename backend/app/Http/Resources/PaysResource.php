<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Pays;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Pays
 */
final class PaysResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code_iso' => $this->code_iso,
            'nom' => $this->nom,
            'indicatif' => $this->indicatif,
        ];
    }
}
