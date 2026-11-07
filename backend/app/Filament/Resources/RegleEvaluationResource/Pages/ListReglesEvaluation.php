<?php

declare(strict_types=1);

namespace App\Filament\Resources\RegleEvaluationResource\Pages;

use App\Filament\Resources\RegleEvaluationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReglesEvaluation extends ListRecords
{
    protected static string $resource = RegleEvaluationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
