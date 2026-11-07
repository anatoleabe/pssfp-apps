<?php

declare(strict_types=1);

namespace App\Filament\Resources\JuryResource\Pages;

use App\Filament\Resources\JuryResource;
use Filament\Resources\Pages\EditRecord;

class EditJury extends EditRecord
{
    protected static string $resource = JuryResource::class;
}
