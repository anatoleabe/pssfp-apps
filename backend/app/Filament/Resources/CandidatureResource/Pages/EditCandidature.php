<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource\Pages;

use App\Filament\Resources\CandidatureResource;
use App\Models\Candidature;
use Filament\Resources\Pages\EditRecord;

class EditCandidature extends EditRecord
{
    protected static string $resource = CandidatureResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Lecture seule pour les dossiers décidés (cf. P-min-1 PR D),
     * sauf super_admin qui peut corriger une erreur exceptionnelle.
     */
    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        /** @var Candidature $record */
        $record = $this->record;
        if (in_array($record->statut, [Candidature::STATUT_ACCEPTE, Candidature::STATUT_REFUSE], true)
            && ! auth()->user()->hasRole('super_admin')) {
            abort(403, 'Ce dossier a été décidé. Seul un super_admin peut corriger une erreur exceptionnelle.');
        }
    }

    /**
     * Empêche toute modification des champs systèmes via le form Filament
     * (ajout 5 PR D, P-min-4). Les champs sont déjà disabled() dans
     * CandidatureResource::form, mais on filtre aussi côté save par
     * sécurité défensive.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $forbidden = [
            'id', 'uuid', 'numero_dossier', 'campagne_id', 'user_id', 'phone_e164',
            'statut', 'submitted_at', 'reviewed_at', 'decided_at', 'withdrawn_at',
            'frais_paye', 'mode_paiement', 'reference_paiement', 'date_paiement',
            'recipisse_pdf_path', 'recipisse_hash_sha256',
            'created_at', 'updated_at', 'deleted_at',
        ];

        return array_diff_key($data, array_flip($forbidden));
    }
}
