import type { Pays, Specialite, StatutActuel } from '@/lib/api/types';
import type { AutreDiplomeRow, FormationProRow } from '@/lib/diplomes/rows';

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
  lieu_naissance: string;
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
  diplome_requis: string;
  annee_diplome_requis: number | '';
  domaine_diplome_requis: string;
  specialite_diplome_requis: string;
  institut_diplome_requis: string;
  autres_diplomes: AutreDiplomeRow[];
  formations_professionnelles: FormationProRow[];
  statut_actuel: StatutActuel | '';
  fonction_actuelle: string;
  employeur: string;
  adresse_employeur: string;
  tel_employeur: string;
  moyen_connaissance: string;
  moyen_connaissance_detail: string;

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
  lieu_naissance: '',
  genre: 'M',
  statut_matrimonial: 'Célibataire',
  nationalite: 'CM',

  pays_origine: 'CM',
  pays_residence: 'CM',
  region: '',
  departement: '',
  adresse: '',
  ville_residence: '',
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
  diplome_requis: '',
  annee_diplome_requis: '',
  domaine_diplome_requis: '',
  specialite_diplome_requis: '',
  institut_diplome_requis: '',
  autres_diplomes: [],
  formations_professionnelles: [],
  statut_actuel: '',
  fonction_actuelle: '',
  employeur: '',
  adresse_employeur: '',
  tel_employeur: '',
  moyen_connaissance: '',
  moyen_connaissance_detail: '',

  engagement_nom: '',
  pin: '',
  pin_confirmation: '',
  cgu: false,
  turnstile_token: '',
};

/**
 * Carte des erreurs du wizard. L'intersection conserve l'autocomplétion sur les
 * champs connus tout en autorisant les clés à chemin complet produites par les
 * blocs répétables (`autres_diplomes.0.intitule`).
 */
export type WizardErrors = Partial<Record<keyof WizardData, string>> & {
  [key: string]: string | undefined;
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
