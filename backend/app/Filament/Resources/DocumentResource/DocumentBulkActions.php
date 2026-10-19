<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Models\MotCle;
use App\Models\User;
use App\Services\Library\DocumentReviewNotifier;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

/**
 * Actions en lot de la liste des documents (Plan 6, Task 4).
 *
 * Type et thèmes : réservés à la gestion du catalogue
 * (DocumentResource::managesCatalogue()) ;
 * chaque document passe par la Policy, donc un document publié n'est touché
 * qu'avec `document.publish` — sinon il est ignoré et compté. Archivage :
 * `document.publish`. Soumission : brouillons modifiables par le compte.
 * Chaque document modifié est réindexé et laisse une trace dans l'historique
 * (journal automatique pour type et statut, entrée explicite pour les thèmes).
 */
final class DocumentBulkActions
{
    /** @return list<BulkAction> */
    public static function make(): array
    {
        return [
            self::changeType(),
            self::addThemes(),
            self::removeThemes(),
            self::archive(),
            self::submit(),
        ];
    }

    private static function changeType(): BulkAction
    {
        return BulkAction::make('changerType')->label('Changer le type')->icon('heroicon-o-tag')
            ->authorize(fn (): bool => DocumentResource::managesCatalogue())
            ->form([Select::make('type')->label('Nouveau type')->options(Document::TYPES)->required()])
            ->action(fn (Collection $records, array $data) => self::applyToEditable($records, function (Document $document) use ($data): bool {
                if ($document->type === $data['type']) {
                    return false;
                }
                $document->update(['type' => $data['type']]);

                return true;
            }))
            ->deselectRecordsAfterCompletion();
    }

    private static function addThemes(): BulkAction
    {
        return BulkAction::make('ajouterThemes')->label('Ajouter des thèmes')->icon('heroicon-o-plus-circle')
            ->authorize(fn (): bool => DocumentResource::managesCatalogue())
            ->form([self::themesField()])
            ->action(function (Collection $records, array $data): void {
                $themes = self::themes($data);
                $records->loadMissing('motsCles');

                self::applyToEditable($records, function (Document $document) use ($themes): bool {
                    $missing = $themes->whereNotIn('id', $document->motsCles->modelKeys());
                    if ($missing->isEmpty()) {
                        return false;
                    }
                    $document->motsCles()->attach($missing->modelKeys());
                    self::logThemes($document, 'mots_cles_added', 'Thèmes ajoutés', $missing);

                    return true;
                });
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function removeThemes(): BulkAction
    {
        return BulkAction::make('retirerThemes')->label('Retirer des thèmes')->icon('heroicon-o-minus-circle')
            ->authorize(fn (): bool => DocumentResource::managesCatalogue())
            ->form([self::themesField()])
            ->action(function (Collection $records, array $data): void {
                $themes = self::themes($data);
                $records->loadMissing('motsCles');

                self::applyToEditable($records, function (Document $document) use ($themes): bool {
                    $present = $themes->whereIn('id', $document->motsCles->modelKeys());
                    if ($present->isEmpty()) {
                        return false;
                    }
                    $document->motsCles()->detach($present->modelKeys());
                    self::logThemes($document, 'mots_cles_removed', 'Thèmes retirés', $present);

                    return true;
                });
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function archive(): BulkAction
    {
        return BulkAction::make('archiverLot')->label('Archiver la sélection')->icon('heroicon-o-archive-box')->color('gray')
            ->authorize(fn (): bool => (bool) auth()->user()?->can('document.publish'))
            ->requiresConfirmation()
            ->modalDescription('Seuls les documents publiés sont archivés : ils quittent le site public.')
            ->action(function (Collection $records): void {
                $published = $records->filter(fn (Document $d): bool => $d->status === Document::STATUS_PUBLISHED)->values();
                $published->each(function (Document $document): void {
                    DocumentResource::archive($document);
                    $document->syncSearchIndex();
                });

                self::notify("{$published->count()} document(s) archivé(s), ".($records->count() - $published->count()).' ignoré(s)', $published->isNotEmpty());
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function submit(): BulkAction
    {
        return BulkAction::make('soumettreLot')->label('Soumettre la sélection')->icon('heroicon-o-paper-airplane')
            ->authorize(fn (): bool => DocumentResource::managesCatalogue())
            ->requiresConfirmation()
            ->modalDescription('Les brouillons de la sélection passent en revue ; les validateurs reçoivent un seul message.')
            ->action(function (Collection $records): void {
                $user = auth()->user();
                $drafts = $records
                    ->filter(fn (Document $d): bool => $d->status === Document::STATUS_DRAFT && (bool) $user?->can('update', $d))
                    ->values();
                $drafts->each(function (Document $document): void {
                    DocumentResource::submit($document, notify: false);
                    $document->syncSearchIndex();
                });
                DocumentReviewNotifier::submittedBatch($drafts, DocumentResource::actor());

                self::notify("{$drafts->count()} document(s) soumis en revue, ".($records->count() - $drafts->count()).' ignoré(s)', $drafts->isNotEmpty());
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Applique `$change` aux documents que le compte peut modifier (Policy),
     * réindexe ceux qui ont changé et rend compte des documents publiés
     * ignorés faute de `document.publish`.
     *
     * @param  Collection<int, Document>  $records
     * @param  Closure(Document): bool  $change  vrai si le document a changé
     */
    private static function applyToEditable(Collection $records, Closure $change): void
    {
        $user = auth()->user();
        $changed = 0;
        $skippedPublished = 0;

        foreach ($records as $document) {
            if (! $user instanceof User || ! $user->can('update', $document)) {
                $skippedPublished += $document->status === Document::STATUS_PUBLISHED ? 1 : 0;

                continue;
            }

            if ($change($document)) {
                $document->syncSearchIndex();
                $changed++;
            }
        }

        $title = "{$changed} document(s) modifié(s)";
        if ($skippedPublished > 0) {
            $title .= " — {$skippedPublished} document(s) publiés ignorés";
        }

        self::notify($title, $changed > 0 && $skippedPublished === 0);
    }

    private static function themesField(): Select
    {
        return Select::make('mots_cles')->label('Thèmes')->multiple()->required()->searchable()
            ->options(fn (): array => MotCle::query()->orderBy('slug')->get(['id', 'slug', 'label'])
                ->mapWithKeys(fn (MotCle $m): array => [$m->id => (string) ($m->getTranslation('label', 'fr', false) ?: $m->slug)])
                ->all());
    }

    /**
     * Thèmes choisis, relus en base (identifiants inconnus écartés).
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, MotCle>
     */
    private static function themes(array $data): Collection
    {
        $ids = array_map('intval', (array) ($data['mots_cles'] ?? []));

        return MotCle::query()->whereKey($ids)->orderBy('id')->get(['id', 'slug']);
    }

    /** Les thèmes sont un pivot : le journal automatique ne les voit pas. */
    private static function logThemes(Document $document, string $event, string $description, Collection $themes): void
    {
        activity('documents')->causedBy(auth()->user())->performedOn($document)
            ->withProperties(['mots_cles' => $themes->pluck('slug')->values()->all()])
            ->event($event)
            ->log($description);
    }

    private static function notify(string $title, bool $success): void
    {
        Notification::make()->title($title)->status($success ? 'success' : 'warning')->send();
    }
}
