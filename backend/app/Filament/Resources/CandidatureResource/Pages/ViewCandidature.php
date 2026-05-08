<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource\Pages;

use App\Filament\Resources\CandidatureResource;
use App\Models\Candidature;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Activitylog\Models\Activity;

class ViewCandidature extends ViewRecord
{
    protected static string $resource = CandidatureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    /**
     * Section Activity timeline (P-min-4 PR D) — affiche les 10 derniers
     * événements activity_log liés au dossier.
     */
    protected function getFooterWidgets(): array
    {
        return [];
    }

    public function getRecentActivity(): Collection
    {
        return Activity::query()
            ->where('subject_type', Candidature::class)
            ->where('subject_id', $this->record->id)
            ->latest('id')
            ->limit(10)
            ->get();
    }
}
