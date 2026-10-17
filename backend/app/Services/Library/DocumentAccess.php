<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Models\Document;
use App\Models\User;

/**
 * Porte d'accès unique aux fichiers de la bibliothèque (spec V1 §4).
 *
 * Points d'extension prévus, sans changer les appelants :
 * - sous-projet 4 : rôle du lecteur à l'étape « réservé » ;
 * - sous-projet 5 : droit acheté (document_entitlements) à l'étape « payant ».
 */
final class DocumentAccess
{
    public function decide(?User $user, Document $document): AccessDecision
    {
        if (! $document->isPublished()) {
            return new AccessDecision(false, AccessDecision::UNPUBLISHED);
        }

        if ($document->external_url !== null) {
            return new AccessDecision(true, AccessDecision::EXTERNAL);
        }

        if ($document->price_xaf !== null) {
            return new AccessDecision(false, AccessDecision::PAID);
        }

        if ($document->access_level !== 'public') {
            return new AccessDecision(false, AccessDecision::RESERVED);
        }

        return new AccessDecision(true, AccessDecision::PUBLIC);
    }
}
