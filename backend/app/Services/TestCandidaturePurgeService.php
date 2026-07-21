<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Candidature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Suppression contrôlée des seuls comptes de recette jamais soumis. */
final class TestCandidaturePurgeService
{
    public function purge(Candidature $candidature): void
    {
        if ($candidature->statut !== Candidature::STATUT_POSTULANT || $candidature->submitted_at !== null) {
            throw new RuntimeException('Seul un dossier Postulant jamais soumis peut être supprimé.');
        }

        $candidature->loadMissing(['documents', 'user']);
        $paths = $candidature->documents->pluck('path')->filter()->values()->all();
        if ($candidature->photo_path) {
            $paths[] = $candidature->photo_path;
        }
        if ($candidature->recipisse_pdf_path) {
            $paths[] = $candidature->recipisse_pdf_path;
        }

        DB::transaction(function () use ($candidature): void {
            $user = $candidature->user;
            $numero = $candidature->numero_dossier;

            $candidature->documents()->delete();
            $candidature->delete(); // soft-delete conservant la traçabilité minimale

            if ($user !== null && $user->hasRole('candidat')
                && Candidature::withTrashed()->where('user_id', $user->id)->whereKeyNot($candidature->id)->doesntExist()) {
                $user->tokens()->delete();
                $user->delete();
            }

            activity('candidatures')->causedBy(auth()->user())
                ->event('test_candidature_purged')
                ->withProperties(['numero_dossier' => $numero])
                ->log('Compte de recette supprimé et fichiers purgés');
        });

        if ($paths !== []) {
            Storage::disk('minio_candidatures')->delete($paths);
        }
    }
}
