<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Support\BelongsToModule;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * « Historique » de la fiche : entrées du journal d'activité du document,
 * de la plus récente à la plus ancienne, en lecture seule. Visible de qui
 * peut voir le document (un contributeur, pour ses propres dépôts).
 */
#[BelongsToModule('bibliotheque')]
class ActivitiesRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'activities';

    protected static ?string $title = 'Historique';

    protected static ?string $icon = 'heroicon-o-clock';

    /** Libellés des attributs journalisés (Document::getActivitylogOptions()). */
    private const ATTRIBUTE_LABELS = [
        'title' => 'Titre',
        'type' => 'Type',
        'status' => 'Statut',
        'access_level' => 'Accès',
        'price_xaf' => 'Prix (FCFA)',
        'file_path' => 'Fichier',
        'external_url' => 'Lien externe',
        'cover_path' => 'Couverture',
    ];

    private const EVENT_LABELS = [
        'created' => 'Création',
        'updated' => 'Modification',
        'deleted' => 'Suppression',
        'restored' => 'Restauration',
        'document_returned_to_draft' => 'Renvoi en brouillon',
        'mots_cles_added' => 'Thèmes ajoutés',
        'mots_cles_removed' => 'Thèmes retirés',
        'cover_regeneration_requested' => 'Régénération de la couverture',
    ];

    private const VALUE_MAX = 80;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows()
            && $ownerRecord instanceof Document
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);

        parent::mount();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('causer')->reorder()->orderByDesc('created_at')->orderByDesc('id'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Date')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('causer.name')->label('Auteur')->default('Système'),
                Tables\Columns\TextColumn::make('event')->label('Événement')
                    ->getStateUsing(fn (Activity $record): string => self::EVENT_LABELS[(string) $record->event] ?? (string) ($record->description ?: $record->event)),
                Tables\Columns\TextColumn::make('changes')->label('Détail')->listWithLineBreaks()->wrap()
                    ->getStateUsing(fn (Activity $record): array => self::details($record)),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Aucune entrée dans l\'historique');
    }

    /**
     * Lignes « Champ : ancien → nouveau » pour les attributs journalisés,
     * puis le détail des entrées explicites (motif, thèmes).
     *
     * @return list<string>
     */
    public static function details(Activity $activity): array
    {
        $properties = $activity->properties;
        $new = (array) ($properties['attributes'] ?? []);
        $old = (array) ($properties['old'] ?? []);
        $lines = [];

        foreach ($new as $attribute => $value) {
            $label = self::ATTRIBUTE_LABELS[$attribute] ?? $attribute;
            $lines[] = array_key_exists($attribute, $old)
                ? "{$label} : ".self::value($attribute, $old[$attribute]).' → '.self::value($attribute, $value)
                : "{$label} : ".self::value($attribute, $value);
        }

        if (isset($properties['motif'])) {
            $lines[] = 'Motif : '.Str::limit((string) $properties['motif'], 300);
        }

        if (isset($properties['mots_cles'])) {
            $lines[] = 'Thèmes : '.implode(', ', array_map('strval', (array) $properties['mots_cles']));
        }

        return $lines;
    }

    private static function value(string $attribute, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        $text = match ($attribute) {
            'status' => DocumentResource::STATUS_LABELS[$value] ?? (string) $value,
            'type' => Document::TYPES[$value] ?? (string) $value,
            'access_level' => Document::ACCESS_LEVELS[$value] ?? (string) $value,
            'file_path', 'cover_path' => basename((string) $value),
            default => is_array($value) ? (string) ($value['fr'] ?? json_encode($value, JSON_UNESCAPED_UNICODE)) : (string) $value,
        };

        return Str::limit($text, self::VALUE_MAX);
    }
}
