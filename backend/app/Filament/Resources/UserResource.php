<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Gestion des comptes back-office (créer un agent, changer ses rôles, réinitialiser
 * son accès) — remplace la création de comptes par seeder / tinker.
 *
 * Périmètre volontairement limité aux comptes disposant d'un rôle back-office
 * (cf. User::PANEL_ROLES) : les milliers de comptes `candidat` créés par
 * apply.pssfp.org n'ont rien à faire dans cet écran, ils se gèrent via les
 * candidatures. Un filtre permet malgré tout de les retrouver ponctuellement.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'Utilisateur';

    protected static ?string $pluralModelLabel = 'Utilisateurs';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /** Rôles attribuables depuis cet écran (jamais `candidat`). */
    private const ROLE_LABELS = [
        'super_admin' => 'Super administrateur — accès total, y compris réglages et suppressions',
        'admin' => 'Administrateur — comptes, campagnes, réglages, export',
        'editor' => 'Éditeur — contenus du site institutionnel',
        'librarian' => 'Bibliothécaire — bibliothèque virtuelle',
        'admission_committee' => 'Comité d\'admission — étude des dossiers et décisions',
        'receptionniste' => 'Réception scolarité — pointage des dossiers déposés au guichet',
    ];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identité')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nom complet')
                        ->required()
                        ->maxLength(150),
                    Forms\Components\TextInput::make('email')
                        ->label('Adresse email professionnelle')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(150),
                    Forms\Components\TextInput::make('phone_e164')
                        ->label('Téléphone (format international)')
                        ->tel()
                        ->maxLength(20)
                        ->placeholder('+237699000000'),
                    Forms\Components\TextInput::make('phone_country')
                        ->label('Pays du numéro')
                        ->maxLength(2)
                        ->default('CM'),
                ]),

            Forms\Components\Section::make('Accès')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->label('Mot de passe')
                        ->password()
                        ->revealable()
                        ->minLength(12)
                        ->required(fn (string $operation): bool => $operation === 'create')
                        // Le cast `hashed` du modèle chiffre déjà la valeur :
                        // ne pas rehacher ici sous peine de double hash.
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (string $operation): string => $operation === 'create'
                            ? '12 caractères minimum. À transmettre à l\'agent par un canal sûr ; il pourra le changer ensuite.'
                            : 'Laisser vide pour conserver le mot de passe actuel.')
                        ->suffixAction(
                            Forms\Components\Actions\Action::make('generer')
                                ->label('Générer')
                                ->icon('heroicon-m-sparkles')
                                ->action(fn (Forms\Set $set) => $set('password', Str::password(16))),
                        ),
                    Forms\Components\Select::make('roles')
                        ->label('Rôles')
                        ->relationship(
                            name: 'roles',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->whereIn('name', array_keys(self::ROLE_LABELS)),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Role $record): string => $record->name)
                        ->multiple()
                        ->preload()
                        ->required()
                        ->helperText(self::rolesHelperText())
                        // Seul un super_admin fabrique un autre super_admin.
                        ->disableOptionWhen(fn (string $value): bool => $value === 'super_admin'
                            && auth()->user()?->hasRole('super_admin') !== true)
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('email_verified_at')
                        ->label('Compte actif (email vérifié)')
                        ->default(true)
                        ->helperText('Décocher bloque la connexion tant que l\'agent n\'a pas vérifié son adresse.')
                        ->formatStateUsing(fn (mixed $state): bool => $state !== null)
                        // On conserve l'horodatage d'origine pour ne pas
                        // réécrire la date de vérification à chaque édition.
                        ->dehydrateStateUsing(fn (bool $state, ?User $record): mixed => $state
                            ? ($record?->email_verified_at ?? now())
                            : null),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Rôles')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'admin' => 'warning',
                        'candidat' => 'gray',
                        default => 'primary',
                    }),
                Tables\Columns\IconColumn::make('two_factor_confirmed_at')
                    ->label('2FA')
                    ->boolean()
                    ->tooltip(fn (User $record): string => $record->two_factor_confirmed_at === null
                        ? 'Double authentification non configurée'
                        : 'Double authentification active'),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Actif')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Rôle')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('email_verified_at')
                    ->label('Compte actif')
                    ->nullable(),
                // Actif par défaut : sans lui, les milliers de comptes candidats
                // rendraient l'écran inutilisable. Décocher pour tout voir.
                Tables\Filters\Filter::make('agents_seulement')
                    ->label('Comptes back-office uniquement')
                    ->toggle()
                    ->default(true)
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'roles',
                        fn (Builder $roles): Builder => $roles->whereIn('name', User::PANEL_ROLES),
                    )),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('reset_2fa')
                    ->label('Réinitialiser la 2FA')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('L\'agent devra reconfigurer sa double authentification à sa prochaine connexion. À n\'utiliser qu\'en cas de perte du téléphone.')
                    ->visible(fn (User $record): bool => auth()->user()?->hasRole('super_admin') === true
                        && $record->two_factor_secret !== null)
                    ->action(function (User $record): void {
                        $record->forceFill([
                            'two_factor_secret' => null,
                            'two_factor_recovery_codes' => null,
                            'two_factor_confirmed_at' => null,
                        ])->save();

                        Notification::make()
                            ->title('Double authentification réinitialisée')
                            ->body('L\'agent devra la reconfigurer à sa prochaine connexion.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    private static function rolesHelperText(): string
    {
        return collect(self::ROLE_LABELS)
            ->map(fn (string $label, string $role): string => "{$role} : {$label}")
            ->implode(' · ');
    }
}
