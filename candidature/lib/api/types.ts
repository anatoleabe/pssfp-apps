/**
 * Types des entités API consommées par le frontend candidature.
 * Tenus alignés avec les JsonResources Laravel (PR C) et les retours Sanctum (PR B).
 */

export type ApiResult<T> =
  | { ok: true; data: T }
  | { ok: false; status: number; message: string; errors?: Record<string, string[]> };

export interface Campagne {
  slug: string;
  nom: string;
  promotion_numero: number;
  opens_at: string | null;
  closes_at: string | null;
  results_at: string | null;
  status: 'draft' | 'open' | 'closed' | 'archived';
  max_voeux: number;
  is_currently_open: boolean;
}

export interface Pays {
  code_iso: string;
  nom: string;
  indicatif: string;
}

export interface Region {
  code: string;
  nom: string;
  quota_admission: number | null;
  chef_lieu: string | null;
  order: number;
}

export interface Departement {
  code: string;
  nom: string;
  chef_lieu: string | null;
  region_code: string;
}

export interface Specialite {
  slug: string;
  label: string;
}

export interface RegisterCandidatPayload {
  phone_e164: string;
  phone_country: string;
  pin: string;
  pin_confirmation: string;
  nom: string;
  prenom: string;
  date_naissance: string;
  cgu: boolean;
}

export interface RegisterCandidatResponse {
  user: {
    id: number;
    name: string;
    phone_e164: string;
    phone_country: string;
    roles: string[];
  };
  token: string;
  abilities: string[];
  expires_at: string | null;
}

/**
 * Sous-ensemble du modèle Candidature côté API (cf. backend CandidatureResource PR C).
 * Tous les champs sont optionnels au stade `postulant` (validation laxiste PUT, stricte au submit).
 */
export interface CandidatureProfile {
  civilite?: string;
  nom?: string;
  prenom?: string;
  epouse?: string | null;
  date_naissance?: string;
  lieu_naissance?: string;
  genre?: string;
  statut_matrimonial?: string;
  nationalite?: string;
  pays_origine?: string;
  pays_residence?: string;
  region?: string | null;
  departement?: string | null;
  adresse?: string;
  ville_residence?: string;
  indicatif1?: string;
  telephone1?: string;
  indicatif2?: string | null;
  telephone2?: string | null;
  email?: string | null;
  specialite?: string;
  second_choix?: string | null;
  type_etude?: 'presentiel' | 'distanciel';
  premiere_langue?: 'fr' | 'en';
  diplome_obtenu?: string;
  institut?: string;
  specialite_diplome?: string;
  annee_diplome?: number;
  statut_actuel?: 'Etudiant' | 'Fonctionnaire-Contractuel' | 'Prive';
  employeur?: string | null;
  adresse_employeur?: string | null;
  tel_employeur?: string | null;
  engagement_nom?: string;
  moyen_connaissance?: string | null;
}
