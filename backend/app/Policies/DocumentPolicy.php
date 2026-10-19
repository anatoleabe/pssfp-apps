<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Droits Document (Module 3) — même schéma que les contenus du Module 1.
 *
 * Un document publié est en ligne : le modifier ou le supprimer revient à
 * publier ou dépublier, d'où l'exigence supplémentaire de `document.publish`.
 *
 * Contributeurs (`document.contribute`, Plan 6) : en plus des droits du
 * catalogue, un compte peut voir **ses** dépôts (`uploaded_by`), en créer,
 * et modifier ou supprimer ceux qui sont encore en brouillon. Une fois
 * soumis en revue, le dépôt lui est fermé. La liste est en outre filtrée
 * sur ses dépôts par DocumentResource::getEloquentQuery().
 */
final class DocumentPolicy extends ContentPolicy
{
    protected function resource(): string
    {
        return 'document';
    }

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) || $this->contributes($user);
    }

    public function view(User $user, ?Document $document = null): bool
    {
        return parent::view($user) || ($this->contributes($user) && $this->owns($user, $document));
    }

    public function create(User $user): bool
    {
        return parent::create($user) || $this->contributes($user);
    }

    public function update(User $user, ?Document $document = null): bool
    {
        return (parent::update($user) && $this->canTouchOnline($user, $document))
            || $this->canTouchOwnDraft($user, $document);
    }

    public function delete(User $user, ?Document $document = null): bool
    {
        return (parent::delete($user) && $this->canTouchOnline($user, $document))
            || $this->canTouchOwnDraft($user, $document);
    }

    public function publish(User $user): bool
    {
        return $user->can('document.publish');
    }

    private function canTouchOnline(User $user, ?Document $document): bool
    {
        return $document?->status !== Document::STATUS_PUBLISHED || $this->publish($user);
    }

    private function contributes(User $user): bool
    {
        return $user->can('document.contribute');
    }

    private function owns(User $user, ?Document $document): bool
    {
        return $document !== null
            && $document->uploaded_by !== null
            && (int) $document->uploaded_by === (int) $user->getKey();
    }

    private function canTouchOwnDraft(User $user, ?Document $document): bool
    {
        return $this->contributes($user)
            && $this->owns($user, $document)
            && $document?->status === Document::STATUS_DRAFT;
    }
}
