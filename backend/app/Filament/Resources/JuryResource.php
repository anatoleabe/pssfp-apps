<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\JuryResource\Pages;
use App\Models\Jury;
use App\Models\MembreJury;
use App\Models\Parcours;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;
use App\Support\BelongsToModule;
use App\Support\CandidatureObjectStorage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Jurys désignés par note de service (D17) : président, vice-présidents, rapporteur,
 * membres. La composition se fige à l'ouverture d'une séance (D37).
 */
#[BelongsToModule('lmd')]
class JuryResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Jury::class;

    protected static ?string $modelLabel = 'Jury';

    protected static ?string $pluralModelLabel = 'Jurys';

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = GroupeNavigation::EVALUATIONS;

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make('Désignation')->columns(3)->schema([
                Forms\Components\TextInput::make('annee_academique')->label('Année académique')->required()->regex('/^(\d{4})-(\d{4})$/')->default('2026-2027'),
                Forms\Components\Select::make('niveau')->label('Niveau')->required()->native(false)->options(['M1' => 'Master 1', 'M2' => 'Master 2']),
                Forms\Components\Select::make('parcours_id')->label('Parcours (vide : tous)')->native(false)
                    ->options(fn (): array => Parcours::query()->orderBy('ordre')->get()->mapWithKeys(fn (Parcours $p): array => [$p->id => $p->libelleComplet()])->all()),
                Forms\Components\Select::make('nature')->label('Nature')->required()->native(false)->options(Jury::NATURES)->default('deliberation'),
                Forms\Components\TextInput::make('objet')->label('Objet')->required()->maxLength(500)->columnSpan(2),
                Forms\Components\TextInput::make('acte_reference')->label('Note de service n°')->required()->maxLength(120),
                Forms\Components\DatePicker::make('acte_date')->label('Du')->required()->native(false)->displayFormat('d/m/Y'),
                Forms\Components\TextInput::make('acte_signataire')->label('Signataire')->required()->maxLength(255)->default('Le Doyen de la FSEG'),
            ]),
            Forms\Components\Section::make('Composition')
                ->description('Un seul président et un seul rapporteur. Rattachez le compte de chaque membre qui signera le PV dans la plateforme.')
                ->schema([
                    Forms\Components\Repeater::make('membres')->hiddenLabel()->relationship()->columns(6)->defaultItems(0)
                        ->addActionLabel('Ajouter un membre')->reorderable(false)
                        ->rule(fn (): \Closure => function (string $attribut, $valeur, \Closure $echec): void {
                            $roles = collect((array) $valeur)->pluck('role');
                            if ($roles->filter(fn ($r) => $r === 'president')->count() !== 1 || $roles->filter(fn ($r) => $r === 'rapporteur')->count() !== 1) {
                                $echec('Le jury compte exactement un président et un rapporteur.');
                            }
                        })
                        ->schema([
                            Forms\Components\Select::make('role')->label('Qualité')->required()->native(false)->options(MembreJury::ROLES),
                            Forms\Components\TextInput::make('rang')->label('Rang')->integer()->minValue(1)->maxValue(20)->default(1),
                            Forms\Components\TextInput::make('titre')->label('Titre')->maxLength(20)->placeholder('Pr, Dr, M., Mme'),
                            Forms\Components\TextInput::make('nom')->label('Nom')->required()->maxLength(255)->columnSpan(2),
                            Forms\Components\Select::make('user_id')->label('Compte (signature)')->searchable()
                                ->getSearchResultsUsing(fn (string $search): array => User::query()->where('name', 'ilike', "%{$search}%")
                                    ->whereHas('roles', fn ($q) => $q->whereNotIn('name', ['candidat']))->limit(20)->pluck('name', 'id')->all())
                                ->getOptionLabelUsing(fn ($value): ?string => User::query()->find($value)?->name),
                        ]),
                ]),
        ], ['regex' => 'Saisissez l\'année au format 2026-2027.']));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('parcours')->withCount('membres'))
            ->defaultSort('acte_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('annee_academique')->label('Année'),
                Tables\Columns\TextColumn::make('niveau')->label('Niveau')->badge(),
                Tables\Columns\TextColumn::make('parcours.code')->label('Parcours')->placeholder('Tous'),
                Tables\Columns\TextColumn::make('objet')->label('Objet')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('acte_reference')->label('Note de service')->description(fn (Jury $r): string => 'du '.$r->acte_date?->format('d/m/Y')),
                Tables\Columns\TextColumn::make('membres_count')->label('Membres')->alignEnd(),
                Tables\Columns\IconColumn::make('acte_piece_path')->label('Acte numérisé')->boolean()->getStateUsing(fn (Jury $r): bool => $r->acte_piece_path !== null),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('joindreActe')->label('Joindre l\'acte')->icon('heroicon-o-paper-clip')->color('gray')
                    ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::DELIBERATION_PREPARER))
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\FileUpload::make('acte')->label('Note de service signée (PDF)')->required()
                            ->disk(CandidatureObjectStorage::DISK)->directory(Jury::DOSSIER_ACTES)->visibility('private')
                            ->fetchFileInformation(false)->acceptedFileTypes(['application/pdf'])->maxSize(10240)
                            ->getUploadedFileUsing(fn (string $file): ?array => null)
                            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): ?string {
                                try {
                                    $chemin = $file->storeAs(Jury::DOSSIER_ACTES, Str::ulid().'.pdf', ['disk' => CandidatureObjectStorage::DISK]);
                                } catch (Throwable) {
                                    return null;
                                }

                                return $chemin === false ? null : $chemin;
                            }),
                    ], ['mimetypes' => 'Format accepté : PDF.']))
                    ->action(function (Jury $record, array $data): void {
                        abort_unless(auth()->user()?->can(PermissionsLmd::DELIBERATION_PREPARER), 403);
                        $chemin = (string) ($data['acte'] ?? '');
                        if (! Jury::cheminActeValide($chemin)) {
                            Notification::make()->danger()->title('Acte non enregistré')->body('Le stockage n\'a pas répondu : réessayez.')->send();

                            return;
                        }
                        $record->forceFill(['acte_piece_path' => $chemin])->save();
                        Notification::make()->success()->title('Note de service jointe')->send();
                    }),
                Tables\Actions\Action::make('voirActe')->label('Acte')->icon('heroicon-o-document')->color('gray')
                    ->visible(fn (Jury $record): bool => Jury::cheminActeValide($record->acte_piece_path))
                    ->action(function (Jury $record) {
                        abort_unless(Jury::cheminActeValide($record->acte_piece_path) && auth()->user()?->can(PermissionsLmd::CONSULTER), 403);

                        return redirect()->away(Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl(
                            (string) $record->acte_piece_path, now()->addMinutes(30), ['ResponseContentDisposition' => 'inline; filename="note-de-service.pdf"']));
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJurys::route('/'),
            'create' => Pages\CreateJury::route('/create'),
            'edit' => Pages\EditJury::route('/{record}/edit'),
        ];
    }
}
