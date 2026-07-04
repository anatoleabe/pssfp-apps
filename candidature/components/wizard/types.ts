import type { Pays, Specialite } from '@/lib/api/types';

export interface WizardData {
  // Step 1 — Identité & vœu
  specialite: string;
  type_etude: 'presentiel' | 'distanciel';
  premiere_langue: 'fr' | 'en';
  civilite: 'M.' | 'Mme' | 'Mlle';
  nom: string;
  prenom: string;
  epouse: string;
  date_naissance: string; // YYYY-MM-DD
  genre: 'M' | 'F' | 'autre';
  statut_matrimonial: string;
  nationalite: string; // ISO-2

  // Step 2 — Coordonnées
  pays_origine: string;
  pays_residence: string;
  region: string;
  departement: string;
  adresse: string;
  ville_residence: string;
  lieu_naissance: string;
  indicatif1: string;
  telephone1: string;
  phone_country: string;
  phone_e164: string;
  indicatif2: string;
  telephone2: string;
  email: string;

  // Step 3 — Diplôme & profession
  diplome_obtenu: string;
  institut: string;
  specialite_diplome: string;
  annee_diplome: number | '';
  statut_actuel: 'Etudiant' | 'Fonctionnaire-Contractuel' | 'Prive' | '';
  employeur: string;
  adresse_employeur: string;
  tel_employeur: string;
  moyen_connaissance: string;

  // Step 4 — Engagement & PIN
  engagement_nom: string;
  pin: string;
  pin_confirmation: string;
  cgu: boolean;
  // Jeton Cloudflare Turnstile (anti-robot). Non persisté en sessionStorage
  // (single-use, courte durée) — regénéré à chaque affichage de l'étape 4.
  turnstile_token: string;
}

export const initialWizardData: WizardData = {
  specialite: '',
  type_etude: 'presentiel',
  premiere_langue: 'fr',
  civilite: 'M.',
  nom: '',
  prenom: '',
  epouse: '',
  date_naissance: '',
  genre: 'M',
  statut_matrimonial: 'Célibataire',
  nationalite: 'CM',

  pays_origine: 'CM',
  pays_residence: 'CM',
  region: '',
  departement: '',
  adresse: '',
  ville_residence: '',
  lieu_naissance: '',
  indicatif1: '+237',
  telephone1: '',
  phone_country: 'CM',
  phone_e164: '',
  indicatif2: '',
  telephone2: '',
  email: '',

  diplome_obtenu: '',
  institut: '',
  specialite_diplome: '',
  annee_diplome: '',
  statut_actuel: '',
  employeur: '',
  adresse_employeur: '',
  tel_employeur: '',
  moyen_connaissance: '',

  engagement_nom: '',
  pin: '',
  pin_confirmation: '',
  cgu: false,
  turnstile_token: '',
};

export interface WizardServerActionPayload {
  data: WizardData;
}

export interface WizardServerActionResult {
  ok: boolean;
  redirectTo?: string;
  errors?: Record<string, string>;
  cta?: { label: string; href: string };
  message?: string;
}

export interface WizardSharedProps {
  pays: Pays[];
  specialites: Specialite[];
}
