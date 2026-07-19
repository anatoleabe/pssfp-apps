<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Candidature;
use App\Services\PhotoUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Candidature
 */
final class CandidatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'numero_dossier' => $this->numero_dossier,
            'statut' => $this->statut,
            'campagne' => CampagneCandidatureResource::make($this->whenLoaded('campagne')),

            // Identité
            'civilite' => $this->civilite,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'epouse' => $this->epouse,
            'date_naissance' => optional($this->date_naissance)->toDateString(),
            'lieu_naissance' => $this->lieu_naissance,
            'genre' => $this->genre,
            'statut_matrimonial' => $this->statut_matrimonial,

            // Géographie
            'nationalite' => $this->nationalite,
            'pays_origine' => $this->pays_origine,
            'pays_residence' => $this->pays_residence,
            'region' => $this->region,
            'departement' => $this->departement,
            'adresse' => $this->adresse,
            'ville_residence' => $this->ville_residence,

            // Contact
            'phone_e164' => $this->phone_e164,
            'phone_country' => $this->phone_country,
            'email' => $this->email,
            'indicatif1' => $this->indicatif1,
            'telephone1' => $this->telephone1,
            'indicatif2' => $this->indicatif2,
            'telephone2' => $this->telephone2,

            // Pédagogique
            'specialite' => $this->specialite,
            'second_choix' => $this->second_choix,
            'type_etude' => $this->type_etude,
            'premiere_langue' => $this->premiere_langue,
            'diplome_obtenu' => $this->diplome_obtenu,
            'institut' => $this->institut,
            'specialite_diplome' => $this->specialite_diplome,
            'annee_diplome' => $this->annee_diplome,
            'statut_actuel' => $this->statut_actuel,
            'employeur' => $this->employeur,
            'adresse_employeur' => $this->adresse_employeur,
            'tel_employeur' => $this->tel_employeur,

            // Engagement / marketing
            'engagement_nom' => $this->engagement_nom,
            'moyen_connaissance' => $this->moyen_connaissance,

            // Pièces / PDF — photo_path interne pas exposé (chemin MinIO privé) ;
            // seul photo_url signée 30 min + has_photo bool sont remontés au client.
            'has_photo' => $this->photo_path !== null,
            'photo_url' => $this->photo_path !== null
                ? app(PhotoUploadService::class)->signedUrl($this->photo_path)
                : null,
            'recipisse_available' => $this->recipisse_pdf_path !== null,
            // Pièces justificatives optionnelles (cf. migration
            // candidature_documents) — non-bloquantes, dépôt physique
            // alternatif possible au bureau de la scolarité.
            'documents' => CandidatureDocumentResource::collection($this->whenLoaded('documents')),

            // Frais (informatif candidat)
            'frais_paye' => (bool) $this->frais_paye,

            // Timeline
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'reviewed_at' => optional($this->reviewed_at)->toIso8601String(),
            'decided_at' => optional($this->decided_at)->toIso8601String(),
            'withdrawn_at' => optional($this->withdrawn_at)->toIso8601String(),

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
