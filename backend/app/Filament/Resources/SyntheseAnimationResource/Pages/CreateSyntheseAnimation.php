<?php

declare(strict_types=1);

namespace App\Filament\Resources\SyntheseAnimationResource\Pages;

use App\Filament\Resources\SyntheseAnimationResource;
use App\Models\ProgrammationEnseignant;
use App\Models\User;
use App\Services\Lmd\SynthesesAnimation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateSyntheseAnimation extends CreateRecord
{
    protected static string $resource = SyntheseAnimationResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Nouvelle synthèse d\'animation';
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('programmation_id')->label('Enseignant et cours')->required()->searchable()->native(false)
                ->options(fn (): array => SyntheseAnimationResource::programmations())
                ->getSearchResultsUsing(fn (string $search): array => SyntheseAnimationResource::programmations($search))
                ->getOptionLabelUsing(fn ($value): ?string => ($p = ProgrammationEnseignant::query()->with(['enseignant', 'quota.ecue'])->find((int) $value)) === null
                    ? null : SyntheseAnimationResource::libelleProgrammation($p))
                ->validationMessages(['required' => 'Choisissez l\'enseignant et le cours.'])
                ->helperText('Programmations validées, notifiées ou acceptées, sans synthèse ; recherche par nom ou par cours. La période du cours est reprise de la programmation.')
                ->live()
                ->afterStateUpdated(function (?string $state, Forms\Set $set): void {
                    $p = $state === null ? null : ProgrammationEnseignant::query()->find((int) $state);
                    if ($p !== null) {
                        [$du, $au] = app(SynthesesAnimation::class)->periodeProposee($p);
                        $set('periode_du', $du);
                        $set('periode_au', $au);
                    }
                }),
            ...SyntheseAnimationResource::champs(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $auteur */
        $auteur = auth()->user();
        try {
            return app(SynthesesAnimation::class)->creer(ProgrammationEnseignant::query()->findOrFail((int) $data['programmation_id']), $data, $auteur);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Synthèse non créée')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return SyntheseAnimationResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
