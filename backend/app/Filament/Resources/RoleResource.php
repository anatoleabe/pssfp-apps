<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\Role;
use App\Support\ModulePermissionMap;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Écran de composition des rôles back-office.
 *
 * L'administrateur ne manipule jamais de permissions à l'unité : il choisit,
 * module par module, un niveau d'accès (aucun / lecture / gestion), puis coche
 * les actions sensibles de chaque module ouvert. La traduction en permissions
 * Spatie est faite par ModulePermissionMap, seule source de vérité — partagée
 * avec le RolePermissionSeeder.
 *
 * Les champs `modules.*`, `sensitive_actions.*` et `standalone_actions.*`
 * (une liste par module) ne sont pas des colonnes : ils sont calculés à
 * l'ouverture du formulaire et retraduits à l'enregistrement
 * (cf. Pages\CreateRole / Pages\EditRole).
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $modelLabel = 'Rôle';

    protected static ?string $pluralModelLabel = 'Rôles';

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identité du rôle')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->label('Nom du rôle')
                        ->required()
                        ->maxLength(150)
                        ->placeholder('Secrétariat pédagogique')
                        ->live(onBlur: true)
                        // L'identifiant technique se déduit du libellé à la
                        // création puis n'est plus touché : il est référencé
                        // par le code (`hasRole('admin')`) et les seeders.
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state, string $operation): void {
                            if ($operation === 'create' && blank($get('name'))) {
                                $set('name', Str::slug((string) $state, '_'));
                            }
                        }),
                    Forms\Components\TextInput::make('name')
                        ->label('Identifiant technique')
                        ->required()
                        ->maxLength(120)
                        ->unique(ignoreRecord: true)
                        ->rule('regex:/^[a-z][a-z0-9_]*$/')
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText('Minuscules et underscores. Fixé à la création : il est utilisé par le code et ne se renomme pas.'),
                    Forms\Components\Textarea::make('description')
                        ->label('À quoi sert ce rôle ?')
                        ->maxLength(255)
                        ->rows(2)
                        ->placeholder('Saisit les actualités et gère la médiathèque, sans accès aux dossiers de candidature.')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Périmètre par module')
                ->description('Chaque module du back-office reçoit un niveau d\'accès. « Lecture seule » permet de consulter sans rien modifier.')
                ->schema(
                    collect(ModulePermissionMap::modules())
                        ->map(fn (array $module, string $key): Forms\Components\Radio => Forms\Components\Radio::make("modules.{$key}")
                            ->label($module['label'])
                            ->helperText($module['description'])
                            ->options(ModulePermissionMap::levels())
                            ->default(ModulePermissionMap::LEVEL_NONE)
                            ->required()
                            ->inline()
                            ->inlineLabel(false)
                            ->live())
                        ->values()
                        ->all(),
                ),

            ...static::sensitiveActionSections(),
        ]);
    }

    /**
     * Une section d'actions sensibles par module qui en a.
     *
     * Les actions ordinaires (`sensitive_actions.{module}`) ne s'affichent que
     * si le module a un niveau d'accès : sans accès, elles n'auraient aucune
     * prise. Les actions autonomes (`standalone_actions.{module}`, ex. dépôt
     * contributeur) restent proposées au niveau « aucun ». Filament
     * n'enregistre pas un champ masqué ; chaque module ayant ses propres
     * listes, masquer l'une ne vide jamais les autres (cf. mergeSensitiveActions()).
     *
     * @return list<Forms\Components\Section>
     */
    private static function sensitiveActionSections(): array
    {
        return collect(ModulePermissionMap::modules())
            ->filter(fn (array $module): bool => $module['sensitive'] !== [])
            ->map(function (array $module, string $key): Forms\Components\Section {
                $standalone = $module['standalone'] ?? [];
                $ordinary = array_diff_key($module['sensitive'], array_flip($standalone));
                $opened = fn (Forms\Get $get): bool => $get("modules.{$key}") !== ModulePermissionMap::LEVEL_NONE;

                $fields = [];

                if ($ordinary !== []) {
                    $fields[] = Forms\Components\CheckboxList::make("sensitive_actions.{$key}")
                        ->hiddenLabel()
                        ->options($ordinary)
                        ->columns(2)
                        ->bulkToggleable(count($ordinary) > 1)
                        ->visible($opened);
                }

                if ($standalone !== []) {
                    $fields[] = Forms\Components\CheckboxList::make("standalone_actions.{$key}")
                        ->label('Sans accès au module')
                        ->helperText('Ces actions valent même au niveau « Aucun accès » : le compte ne voit que ce qu\'elles ouvrent.')
                        ->options(array_intersect_key($module['sensitive'], array_flip($standalone)))
                        ->columns(2);
                }

                return Forms\Components\Section::make('Actions sensibles — '.(Str::after($module['label'], '— ') ?: $module['label']))
                    ->description($module['sensitive_description'] ?? null)
                    ->schema($fields)
                    ->visible(fn (Forms\Get $get): bool => $standalone !== [] || $opened($get));
            })
            ->values()
            ->all();
    }

    /**
     * Réunit les listes d'actions sensibles par module en une seule liste.
     *
     * Accepte plusieurs jeux de listes (actions ordinaires, actions autonomes).
     *
     * @param  mixed  ...$byModule  module => list de permissions (champs visibles)
     * @return list<string>
     */
    public static function mergeSensitiveActions(mixed ...$byModule): array
    {
        return collect($byModule)
            ->map(fn (mixed $lists): array => is_array($lists) ? $lists : [])
            ->flatten()
            ->filter(fn (mixed $permission): bool => is_string($permission))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Opération inverse, pour pré-remplir le formulaire : module => actions
     * cochées, hors actions autonomes (cf. standaloneActionsByModule()).
     *
     * @param  list<string>  $permissions
     * @return array<string, list<string>>
     */
    public static function sensitiveActionsByModule(array $permissions): array
    {
        return collect(ModulePermissionMap::modules())
            ->filter(fn (array $module): bool => $module['sensitive'] !== [])
            ->map(fn (array $module): array => array_values(array_intersect(
                array_diff(array_keys($module['sensitive']), $module['standalone'] ?? []),
                $permissions,
            )))
            ->all();
    }

    /**
     * Actions autonomes cochées, par module (seuls les modules qui en ont).
     *
     * @param  list<string>  $permissions
     * @return array<string, list<string>>
     */
    public static function standaloneActionsByModule(array $permissions): array
    {
        return collect(ModulePermissionMap::modules())
            ->filter(fn (array $module): bool => ($module['standalone'] ?? []) !== [])
            ->map(fn (array $module): array => array_values(array_intersect($module['standalone'], $permissions)))
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('label')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['users', 'permissions']))
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->label('Rôle')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Medium)
                    ->formatStateUsing(fn (Role $record): string => $record->display_name)
                    ->description(fn (Role $record): ?string => $record->description),
                Tables\Columns\TextColumn::make('name')
                    ->label('Identifiant')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                Tables\Columns\TextColumn::make('perimetre')
                    ->label('Périmètre')
                    ->badge()
                    ->color('primary')
                    ->state(fn (Role $record): array => static::perimeterSummary($record))
                    ->placeholder('Aucun module'),
                Tables\Columns\TextColumn::make('users_count')
                    ->label('Comptes')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    /**
     * Résumé lisible du périmètre — « Admissions : lecture » plutôt qu'une
     * liste de 14 permissions.
     *
     * @return list<string>
     */
    public static function perimeterSummary(Role $role): array
    {
        if ($role->name === 'super_admin') {
            return ['Accès total'];
        }

        $permissions = $role->permissions->pluck('name')->all();
        $modules = ModulePermissionMap::modules();

        return collect(ModulePermissionMap::toLevels($permissions))
            ->map(function (string $level, string $key) use ($modules, $permissions): ?string {
                $label = Str::after($modules[$key]['label'], '— ') ?: $modules[$key]['label'];

                return match (true) {
                    $level === ModulePermissionMap::LEVEL_MANAGE => "{$label} : gestion",
                    $level === ModulePermissionMap::LEVEL_READ => "{$label} : lecture",
                    // Action autonome sans niveau (dépôt contributeur…).
                    array_intersect($modules[$key]['standalone'] ?? [], $permissions) !== [] => "{$label} : actions autonomes",
                    default => null,
                };
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Applique un périmètre (niveaux + actions sensibles) à un rôle.
     *
     * Ne synchronise que les permissions réellement présentes en base : une
     * permission d'un module pas encore livré ne doit pas faire échouer
     * l'enregistrement du rôle.
     *
     * @param  array<string, string>  $levels
     * @param  list<string>  $sensitiveActions
     */
    public static function applyPerimeter(Role $role, array $levels, array $sensitiveActions): void
    {
        $wanted = ModulePermissionMap::toPermissions($levels, $sensitiveActions);

        $existing = Permission::query()
            ->whereIn('name', $wanted)
            ->where('guard_name', $role->guard_name)
            ->get();

        $role->syncPermissions($existing);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        activity('roles')
            ->causedBy(auth()->user())
            ->performedOn($role)
            ->withProperties([
                'levels' => $levels,
                'sensitive_actions' => array_values($sensitiveActions),
                'permissions' => $existing->pluck('name')->all(),
            ])
            ->event('role_perimeter_updated')
            ->log("Périmètre du rôle « {$role->display_name} » mis à jour");
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
