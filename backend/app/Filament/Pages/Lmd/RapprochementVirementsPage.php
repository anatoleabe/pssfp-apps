<?php

declare(strict_types=1);

namespace App\Filament\Pages\Lmd;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\AvisVirement;
use App\Services\Lmd\RapprochementVirements;
use App\Support\BelongsToModule;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;

/**
 * Lot 13c-1 (D38) : contrôle PAI_RAPPROCHEMENT. Les écarts entre la paie et les virements reçus, tous états
 * confondus ; saisie d'un virement sans état. La pastille compte les anomalies (encart « À traiter »).
 */
#[BelongsToModule('lmd')]
class RapprochementVirementsPage extends Page
{
    use ChecksModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = GroupeNavigation::ENSEIGNANTS_PAIE;

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Rapprochement des virements';

    protected static ?string $title = 'Rapprochement des virements';

    protected static ?string $slug = 'lmd/rapprochement-virements';

    protected static string $view = 'filament.lmd.rapprochement-virements';

    public static function canAccess(): bool
    {
        return static::moduleAllows() && RapprochementVirements::peutConsulter(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        if (! RapprochementVirements::peutConsulter(auth()->user())) {
            return null;
        }
        $n = app(RapprochementVirements::class)->nombreAnomalies();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Écarts entre la paie et les virements';
    }

    /** @return list<array<string, mixed>> */
    public function anomalies(): array
    {
        return app(RapprochementVirements::class)->anomalies();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('virementSansEtat')->label('Virement sans état')->icon('heroicon-o-plus')
                ->visible(fn (): bool => RapprochementVirements::peutSaisir(auth()->user()))
                ->modalDescription('Un virement reçu par un enseignant qui ne correspond à aucun état de paie. Il reste signalé comme écart jusqu\'à son annulation.')
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('enseignant_id')->label('Enseignant')->required()->searchable()
                        // Enseignants seulement (rôle ou ligne de paie), recherchés par leur nom.
                        ->getSearchResultsUsing(fn (string $recherche): array => RapprochementVirements::enseignants()
                            ->where('name', 'ilike', '%'.$recherche.'%')->orderBy('name')->limit(20)->pluck('name', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => RapprochementVirements::enseignants()->whereKey($value)->value('name')),
                    Forms\Components\TextInput::make('montant')->label('Montant viré (FCFA)')->integer()->minValue(1)->required(),
                    Forms\Components\DatePicker::make('date_valeur')->label('Date de valeur')->native(false)->displayFormat('d/m/Y')->maxDate(now('Africa/Douala'))->required(),
                    Forms\Components\TextInput::make('reference')->label('Référence')->required()->maxLength(100),
                    Forms\Components\Select::make('source')->label('Source')->options(AvisVirement::SOURCES)->default('releve_bancaire')->required()->native(false),
                ]))
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    app(RapprochementVirements::class)->enregistrer(null, [[
                        'enseignant_id' => (int) $data['enseignant_id'], 'montant' => (int) $data['montant'], 'date_valeur' => (string) $data['date_valeur'],
                        'reference' => (string) $data['reference'], 'source' => (string) $data['source'],
                    ]], auth()->user());

                    return 'Virement sans état enregistré.';
                })),
        ];
    }
}
