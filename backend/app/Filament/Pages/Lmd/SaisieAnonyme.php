<?php

declare(strict_types=1);

namespace App\Filament\Pages\Lmd;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Models\CopieAnonyme;
use App\Models\EpreuveAnonyme;
use App\Models\User;
use App\Services\Lmd\Anonymat;
use App\Services\Lmd\PermissionsLmd;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;

/**
 * Saisie des notes d'examen sur les numéros d'anonymat (RG-047, RG-048) : l'opérateur ne voit
 * que des numéros ; deux opérateurs différents par correction, un troisième tranche un écart.
 */
#[BelongsToModule('lmd')]
class SaisieAnonyme extends Page implements HasForms
{
    use ChecksModuleAccess;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?int $navigationSort = 46;

    protected static ?string $title = 'Saisie sur numéros';

    protected static ?string $slug = 'lmd/saisie-anonyme';

    protected static string $view = 'filament.lmd.saisie-anonyme';

    /** @var array<string, mixed> */
    public array $donnees = [];

    public static function canAccess(): bool
    {
        return static::moduleAllows() && (bool) auth()->user()?->can(PermissionsLmd::ANONYMAT_SAISIR);
    }

    public function mount(): void
    {
        $this->form->fill(['correction' => 1]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('donnees')->columns(4)->schema(MessagesValidation::appliquer([
            Forms\Components\Select::make('epreuve')->label('Épreuve')->required()->native(false)->live()->columnSpan(2)
                ->options(fn (): array => EpreuveAnonyme::query()->where('statut', 'anonymisee')->with('ecue')->get()
                    ->mapWithKeys(fn (EpreuveAnonyme $e): array => [$e->id => $e->libelle()])->all()),
            Forms\Components\Select::make('correction')->label('Correction')->required()->native(false)->live()
                ->options([1 => '1re correction', 2 => '2e correction', 3 => '3e correction']),
            Forms\Components\TextInput::make('numero')->label('Numéro')->required()->length(9)->autocomplete('off'),
            Forms\Components\TextInput::make('note')->label('Note / 20')->required()->maxLength(5)->autocomplete('off'),
        ]));
    }

    public function enregistrer(): void
    {
        $data = $this->form->getState();
        $epreuve = EpreuveAnonyme::query()->findOrFail((int) $data['epreuve']);
        /** @var User $operateur */
        $operateur = auth()->user();
        try {
            $r = app(Anonymat::class)->saisir($epreuve, (string) $data['numero'], (int) $data['correction'], (string) $data['note'], $operateur);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Saisie refusée')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->{$r['ecart'] ? 'warning' : 'success'}()
            ->title('Saisie '.$r['saisie'].' enregistrée'.($r['ecart'] ? ' : écart avec la première saisie, un troisième opérateur tranchera' : ''))->send();
        $this->donnees['numero'] = null;
        $this->donnees['note'] = null;
    }

    /** @return list<string> numéros présents dont la correction choisie n'est pas encore arrêtée */
    public function enAttente(): array
    {
        $epreuve = EpreuveAnonyme::query()->where('statut', 'anonymisee')->find((int) ($this->donnees['epreuve'] ?? 0));
        if ($epreuve === null) {
            return [];
        }
        $service = app(Anonymat::class);
        $correction = (int) ($this->donnees['correction'] ?? 1);

        return $epreuve->copies()->where('constat', 'presente')->with('saisies')->get()
            ->filter(fn (CopieAnonyme $c): bool => in_array($correction, $service->correctionsAttendues($c), true) && $service->noteCorrection($c, $correction) === null)
            ->pluck('numero')->sort()->values()->all();
    }
}
