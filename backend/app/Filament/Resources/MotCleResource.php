<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\MotCleResource\Pages;
use App\Models\Document;
use App\Models\MotCle;
use App\Support\BelongsToModule;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Thésaurus de la bibliothèque (mots-clés hiérarchiques). */
#[BelongsToModule('bibliotheque')]
class MotCleResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = MotCle::class;

    protected static ?string $modelLabel = 'Mot-clé';

    protected static ?string $pluralModelLabel = 'Thésaurus';

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Module 3 — Bibliothèque';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label.fr')->label('Libellé')->required()->maxLength(120),
            Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(120)
                ->rule(DocumentResource::SLUG_RULE)->validationMessages(['regex' => 'Minuscules, chiffres et tirets simples uniquement (ex. : dette-publique).']),
            Forms\Components\Select::make('parent_id')->label('Terme parent')
                ->relationship('parent', 'slug', fn (Builder $query, ?MotCle $record) => $record ? $query->whereKeyNot($record->getKey()) : $query)
                ->searchable()->preload()
                ->rule(fn (?MotCle $record): Closure => self::parentIsNotDescendant($record)),
        ]);
    }

    /** Un terme ne peut être ni son propre parent, ni le parent de l'un de ses ancêtres. */
    private static function parentIsNotDescendant(?MotCle $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record === null || blank($value)) {
                return;
            }

            $seen = [];
            $current = MotCle::query()->find($value);
            while ($current !== null && ! in_array($current->getKey(), $seen, true)) {
                if ($current->is($record)) {
                    $fail('Un terme ne peut pas être son propre parent, ni celui de l\'un de ses ancêtres.');

                    return;
                }
                $seen[] = $current->getKey();
                $current = $current->parent;
            }
        };
    }

    /**
     * Chemins « Racine › … › Terme », comptes de documents publiés directs et avec sous-thèmes
     * (même agrégation que l'API publique), calculés en quelques requêtes pour tout le thésaurus.
     *
     * @return array{paths: array<int, string>, direct: array<int, int>, withDescendants: array<int, int>}
     */
    private static function loadThesaurus(): array
    {
        $labels = [];
        $parents = [];
        foreach (MotCle::query()->get(['id', 'parent_id', 'label', 'slug']) as $m) {
            $labels[$m->id] = $m->getTranslation('label', 'fr', false) ?: $m->slug;
            $parents[$m->id] = $m->parent_id;
        }

        $paths = [];
        foreach ($parents as $id => $_) {
            $chain = [];
            $seen = [];
            for ($cursor = $id; $cursor !== null && isset($labels[$cursor]) && ! isset($seen[$cursor]); $cursor = $parents[$cursor]) {
                $seen[$cursor] = true;
                array_unshift($chain, $labels[$cursor]);
            }
            $paths[$id] = implode(' › ', $chain);
        }

        $byKeyword = DB::table('document_mot_cle')
            ->joinSub(Document::query()->published()->select('documents.id'), 'published_documents', 'published_documents.id', '=', 'document_mot_cle.document_id')
            ->get(['document_mot_cle.mot_cle_id', 'document_mot_cle.document_id'])
            ->groupBy('mot_cle_id')
            ->map(fn ($rows) => $rows->pluck('document_id')->all());

        $direct = $byKeyword->map(fn (array $ids): int => count(array_unique($ids)))->all();
        $withDescendants = [];
        foreach (MotCle::descendantsIndex() as $id => $ids) {
            $withDescendants[$id] = collect($ids)->flatMap(fn (int $d) => $byKeyword->get($d, []))->unique()->count();
        }

        return ['paths' => $paths, 'direct' => $direct, 'withDescendants' => $withDescendants];
    }

    /**
     * Tri SQL sur une valeur calculée en PHP : `array_position` (PostgreSQL, ADR-0002) sur la liste
     * des identifiants déjà ordonnée. Les identifiants sont passés par intval (aucune injection
     * possible) et le thésaurus compte une cinquantaine de lignes, donc la liste reste minuscule.
     *
     * @param  list<int>  $orderedIds
     */
    private static function orderByIdList(Builder $query, array $orderedIds, string $direction): Builder
    {
        if ($orderedIds === []) {
            return $query;
        }

        return $query->orderByRaw(
            'array_position(ARRAY['.implode(',', array_map('intval', $orderedIds)).']::bigint[], mots_cles.id::bigint) '.($direction === 'desc' ? 'desc' : 'asc'),
        );
    }

    public static function table(Table $table): Table
    {
        // État de la requête courante, porté par la fermeture (aucune donnée statique partagée).
        $data = new \ArrayObject(['paths' => [], 'direct' => [], 'withDescendants' => []]);

        return $table
            ->modifyQueryUsing(function (Builder $query) use ($data): Builder {
                $data->exchangeArray(self::loadThesaurus());

                return $query->with('parent');
            })
            ->columns([
                Tables\Columns\TextColumn::make('chemin')->label('Chemin')
                    ->getStateUsing(fn (MotCle $record): string => $data['paths'][$record->getKey()] ?? '')
                    ->sortable(query: function (Builder $query, string $direction) use ($data): Builder {
                        $paths = $data['paths'];
                        uasort($paths, fn (string $a, string $b): int => strcasecmp($a, $b));

                        return self::orderByIdList($query, array_keys($paths), $direction);
                    }),
                // `label.fr` seul se lit comme une relation : état et recherche explicites.
                Tables\Columns\TextColumn::make('label.fr')->label('Libellé')
                    ->getStateUsing(fn (MotCle $record): string => $record->getTranslation('label', 'fr', false))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('label->fr', 'ilike', '%'.$search.'%')),
                Tables\Columns\TextColumn::make('parent.label')->label('Parent')
                    ->getStateUsing(fn (MotCle $record): ?string => $record->parent?->getTranslation('label', 'fr', false)),
                Tables\Columns\TextColumn::make('published_documents_count')->label('Documents publiés')
                    ->getStateUsing(fn (MotCle $record): int => $data['direct'][$record->getKey()] ?? 0)
                    ->sortable(query: function (Builder $query, string $direction) use ($data): Builder {
                        $ids = array_keys($data['paths']);
                        usort($ids, fn (int $a, int $b): int => ($data['direct'][$a] ?? 0) <=> ($data['direct'][$b] ?? 0));

                        return self::orderByIdList($query, $ids, $direction);
                    }),
                Tables\Columns\TextColumn::make('published_with_descendants_count')->label('Avec sous-thèmes')
                    ->getStateUsing(fn (MotCle $record): int => $data['withDescendants'][$record->getKey()] ?? 0),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('racine')->label('Racine')
                    ->options(fn (): array => MotCle::query()->whereNull('parent_id')->orderBy('slug')->get()
                        ->mapWithKeys(fn (MotCle $m): array => [$m->id => $m->getTranslation('label', 'fr', false) ?: $m->slug])->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereIn('mots_cles.id', MotCle::selfAndDescendantIds((int) $data['value']))),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalDescription(fn (MotCle $record): string => sprintf(
                        'Ce mot-clé est rattaché à %d document(s) ; il en sera retiré.',
                        $record->documents()->count(),
                    ))
                    ->before(function (MotCle $record, Tables\Actions\DeleteAction $action): void {
                        if ($record->children()->exists()) {
                            Notification::make()->title('Suppression impossible')
                                ->body('Déplacez ou supprimez d\'abord ses sous-thèmes.')->danger()->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->defaultSort('slug');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageMotsCles::route('/')];
    }
}
