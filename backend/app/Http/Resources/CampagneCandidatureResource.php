<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CampagneCandidature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampagneCandidature
 */
final class CampagneCandidatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'nom' => $this->nom,
            'promotion_numero' => $this->promotion_numero,
            'opens_at' => optional($this->opens_at)->toIso8601String(),
            'closes_at' => optional($this->closes_at)->toIso8601String(),
            'results_at' => optional($this->results_at)->toIso8601String(),
            'status' => $this->status,
            'max_voeux' => $this->max_voeux,
            'is_currently_open' => $this->isCurrentlyOpen(),
            // prefix_numero exclus volontairement (interne, pas exposé en API).
        ];
    }
}
