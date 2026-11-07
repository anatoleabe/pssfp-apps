<?php

declare(strict_types=1);

namespace App\Filament\Resources\RegleEvaluationResource\Pages;

use App\Filament\Resources\RegleEvaluationResource;
use App\Models\RegleEvaluation;
use Filament\Resources\Pages\CreateRecord;

/** Une nouvelle année part des valeurs par défaut (D1, D2, D10, D14 à D16, V-02 à V-09). */
class CreateRegleEvaluation extends CreateRecord
{
    protected static string $resource = RegleEvaluationResource::class;

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');
        $this->form->fill(RegleEvaluation::valeursParDefaut(''));
        $this->callHook('afterFill');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return EditRegleEvaluation::verifier($data);
    }
}
