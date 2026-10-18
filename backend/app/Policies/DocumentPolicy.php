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
 */
final class DocumentPolicy extends ContentPolicy
{
    protected function resource(): string
    {
        return 'document';
    }

    public function update(User $user, ?Document $document = null): bool
    {
        return parent::update($user) && $this->canTouchOnline($user, $document);
    }

    public function delete(User $user, ?Document $document = null): bool
    {
        return parent::delete($user) && $this->canTouchOnline($user, $document);
    }

    public function publish(User $user): bool
    {
        return $user->can('document.publish');
    }

    private function canTouchOnline(User $user, ?Document $document): bool
    {
        return $document?->status !== Document::STATUS_PUBLISHED || $this->publish($user);
    }
}
