<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource\Pages;

use App\Filament\Resources\CandidatureResource;
use App\Models\Candidature;
use App\Support\CandidatureDiplomeBlocks;
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
     *
     * Normalise également les blocs répétables : les `Repeater` Filament
     * remontent leurs champs en chaînes, là où l'API stocke `annee` en entier.
     * Sans ce passage, la même donnée aurait deux formes selon le point
     * d'entrée. La liste noire reste alignée sur celle de
     * CandidatureService::updateDraft — les deux chemins d'écriture doivent
     * interdire exactement les mêmes champs.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $forbidden = [
            'id', 'uuid', 'numero_dossier', 'campagne_id', 'user_id', 'phone_e164',
            'statut', 'form_version', 'submitted_at', 'reviewed_at', 'decided_at', 'withdrawn_at',
            'frais_paye', 'mode_paiement', 'reference_paiement', 'date_paiement',
            'recipisse_pdf_path', 'recipisse_hash_sha256',
            'created_at', 'updated_at', 'deleted_at',
        ];

        $clean = array_diff_key($data, array_flip($forbidden));

        // Un champ masqué par `visible()` n'est pas déshydraté par Filament :
        // passer le domaine de « Autres » à « Droit » laisserait sinon l'ancienne
        // spécialité en base, qui se réimprimerait sur un récépissé régénéré.
        // Le front applique déjà cette règle, l'admin doit l'appliquer aussi.
        if (array_key_exists('domaine_diplome_requis', $clean)
            && $clean['domaine_diplome_requis'] !== 'autres') {
            $clean['specialite_diplome_requis'] = null;
        }

        if (array_key_exists('autres_diplomes', $clean)) {
            $clean['autres_diplomes'] = CandidatureDiplomeBlocks::normalize(
                $clean['autres_diplomes'],
                CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS,
            );
        }
        if (array_key_exists('formations_professionnelles', $clean)) {
            $clean['formations_professionnelles'] = CandidatureDiplomeBlocks::normalize(
                $clean['formations_professionnelles'],
                CandidatureDiplomeBlocks::FORMATION_PRO_KEYS,
            );
        }

        return $clean;
    }
}
