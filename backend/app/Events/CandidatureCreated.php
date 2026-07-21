<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Candidature;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CandidatureCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Candidature $candidature) {}
}
