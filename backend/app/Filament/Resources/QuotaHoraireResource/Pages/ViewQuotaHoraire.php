<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuotaHoraireResource\Pages;

use App\Filament\Resources\QuotaHoraireResource;
use Filament\Resources\Pages\ViewRecord;

class ViewQuotaHoraire extends ViewRecord
{
    protected static string $resource = QuotaHoraireResource::class;

    public function getTitle(): string
    {
        return 'Quota '.$this->getRecord()->ecue->code;
    }
}
