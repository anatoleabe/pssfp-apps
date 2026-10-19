<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Filament\Resources\DocumentResource;
use App\Jobs\SendThrottledMail;
use App\Mail\LibraryDocumentPublishedMail;
use App\Mail\LibraryDocumentReturnedMail;
use App\Mail\LibraryDocumentsSubmittedMail;
use App\Mail\LibraryDocumentSubmittedMail;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Notifications de la file de validation de la bibliothèque.
 *
 * Tout passe par `SendThrottledMail` (plafond hébergeur). Jamais de message à
 * l'auteur de l'action, ni à une adresse `.invalid` (compte d'import système
 * et autres comptes techniques). Les URL sont calculées ici, pendant la
 * requête du panneau, et non dans le worker de file.
 */
final class DocumentReviewNotifier
{
    public const PUBLISH_PERMISSION = 'document.publish';

    /** Soumission en revue : un message à chaque validateur, sauf l'auteur de l'action. */
    public static function submitted(Document $document, ?User $actor): void
    {
        $summary = self::summary($document, 'view');

        foreach (self::publisherEmails($actor) as $email) {
            SendThrottledMail::dispatch([$email], new LibraryDocumentSubmittedMail(
                documentTitle: $summary['title'],
                typeLabel: $summary['type'],
                adminUrl: $summary['url'],
            ));
        }
    }

    /**
     * Soumission en lot : un seul message par validateur, qui liste les
     * documents ; un lot d'un seul document garde le message habituel.
     *
     * @param  Collection<int, Document>  $documents
     */
    public static function submittedBatch(Collection $documents, ?User $actor): void
    {
        if ($documents->count() <= 1) {
            $documents->each(fn (Document $d) => self::submitted($d, $actor));

            return;
        }

        $summaries = $documents->map(fn (Document $d): array => self::summary($d, 'view'))->values()->all();
        $queueUrl = DocumentResource::getUrl('index', ['activeTab' => 'a_valider']);

        foreach (self::publisherEmails($actor) as $email) {
            SendThrottledMail::dispatch([$email], new LibraryDocumentsSubmittedMail($summaries, $queueUrl));
        }
    }

    /**
     * Publication : un seul message par déposant, listant ses documents.
     *
     * @param  Collection<int, Document>  $documents
     */
    public static function published(Collection $documents, ?User $actor): void
    {
        $depositors = self::depositors($documents, $actor);

        $documents
            ->filter(fn (Document $d): bool => $depositors->has($d->uploaded_by))
            ->groupBy('uploaded_by')
            ->each(function (Collection $own, int|string $userId) use ($depositors): void {
                SendThrottledMail::dispatch(
                    [(string) $depositors->get($userId)->email],
                    new LibraryDocumentPublishedMail($own->map(fn (Document $d): array => self::summary($d, 'view'))->values()->all()),
                );
            });
    }

    /** Renvoi en brouillon : le déposant reçoit le motif. */
    public static function returnedToDraft(Document $document, ?User $actor, string $reason): void
    {
        $depositor = self::depositors(collect([$document]), $actor)->first();

        if (! $depositor instanceof User) {
            return;
        }

        // Le brouillon redevient modifiable par son déposant : lien vers l'édition.
        $summary = self::summary($document, 'edit');
        SendThrottledMail::dispatch([(string) $depositor->email], new LibraryDocumentReturnedMail(
            documentTitle: $summary['title'],
            typeLabel: $summary['type'],
            adminUrl: $summary['url'],
            reason: $reason,
        ));
    }

    /** @return list<string> */
    private static function publisherEmails(?User $actor): array
    {
        try {
            $publishers = User::permission(self::PUBLISH_PERMISSION)->get(['users.id', 'users.email']);
        } catch (PermissionDoesNotExist) {
            return [];
        }

        return $publishers
            ->reject(fn (User $u): bool => $actor !== null && $u->is($actor))
            ->pluck('email')
            ->filter(fn (?string $email): bool => self::reachable($email))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Déposants joignables des documents, indexés par identifiant.
     *
     * @param  Collection<int, Document>  $documents
     * @return Collection<int, User>
     */
    private static function depositors(Collection $documents, ?User $actor): Collection
    {
        $ids = $documents->pluck('uploaded_by')->filter()->map(fn (int|string $id): int => (int) $id)->unique()
            ->reject(fn (int $id): bool => $actor !== null && $id === (int) $actor->getKey());

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereKey($ids->all())->get(['id', 'email'])
            ->filter(fn (User $u): bool => self::reachable($u->email))
            ->keyBy('id');
    }

    /** Une adresse réelle : ni vide, ni réservée (`.invalid`, RFC 2606). */
    private static function reachable(?string $email): bool
    {
        return filled($email) && ! Str::endsWith(Str::lower(trim($email)), '.invalid');
    }

    /**
     * Le lien pointe vers la page que le destinataire peut ouvrir : la fiche
     * (`view`) pour les validateurs et pour un document publié (que son
     * contributeur ne peut plus modifier), l'édition (`edit`) pour un
     * brouillon rendu à son déposant.
     *
     * @param  'view'|'edit'  $page
     * @return array{title: string, type: string, url: string}
     */
    private static function summary(Document $document, string $page): array
    {
        return [
            'title' => (string) ($document->getTranslation('title', 'fr', false) ?: $document->slug),
            'type' => Document::TYPES[$document->type] ?? (string) $document->type,
            'url' => DocumentResource::getUrl($page, ['record' => $document]),
        ];
    }
}
