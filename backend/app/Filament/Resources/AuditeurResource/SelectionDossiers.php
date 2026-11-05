<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Models\Auditeur;
use App\Models\Candidature;
use Illuminate\Support\Collection;

/**
 * Les actions groupées des dossiers (comptes eCampus, identifiants, lettres)
 * servent depuis la liste des auditeurs : la sélection y est faite d'auditeurs,
 * ramenés ici à leurs candidatures.
 */
final class SelectionDossiers
{
    /**
     * @param  Collection<int, Auditeur|Candidature>  $records
     * @return Collection<int, Candidature>
     */
    public static function dossiers(Collection $records): Collection
    {
        if ($records->every(fn ($r): bool => $r instanceof Candidature)) {
            return $records;
        }

        $ids = $records->map(fn ($r): int => (int) ($r instanceof Auditeur ? $r->candidature_id : $r->getKey()));

        return Candidature::query()->with(['campagne', 'auditeur.inscriptionCourante.bareme'])->whereIn('id', $ids)->get();
    }
}
