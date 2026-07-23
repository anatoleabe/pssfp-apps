/**
 * Liste des champs éditables depuis /dossier/edition (PR H).
 *
 * Tout ce qui n'est PAS dans cette liste reste verrouillé côté UI :
 * - `phone_e164` : téléphone de CONNEXION du candidat (modif via support).
 *   Distinct de `indicatif1`/`telephone1` qui sont le téléphone de CONTACT
 *   principal — ceux-ci sont requis pour la soumission et donc éditables ici.
 * - `pin` : modifiable uniquement via /forgot-pin.
 * - `numero_dossier`, `uuid`, `statut`, dates, `frais_paye` : champs systèmes
 *   (filtrés côté backend par CandidatureService::updateDraft).
 *
 * Garde-fou côté backend : UpdateCandidatureRequest valide laxiste, et
 * CandidatureService blackliste les champs systèmes (cf. tests Pest
 * PartialUpdateTest "forbids overwriting system fields").
 */
export const EDITABLE_FIELDS = [
  // Identité
  'civilite',
  'nom',
  'prenom',
  'epouse',
  'date_naissance',
  'lieu_naissance',
  'genre',
  'statut_matrimonial',
  'nationalite',
  'specialite',
  'second_choix',
  'type_etude',
  'premiere_langue',
  // Coordonnées
  'pays_origine',
  'pays_residence',
  'region',
  'departement',
  'adresse',
  'ville_residence',
  'indicatif1',
  'telephone1',
  'indicatif2',
  'telephone2',
  'email',
  // Diplôme & profession
  'diplome_obtenu',
  'institut',
  'specialite_diplome',
  'annee_diplome',
  'statut_actuel',
  'fonction_actuelle',
  'employeur',
  'adresse_employeur',
  'tel_employeur',
  'moyen_connaissance',
  'moyen_connaissance_detail',
  // Engagement
  'engagement_nom',
] as const;

export type EditableField = (typeof EDITABLE_FIELDS)[number];

export type EditableFields = Partial<Record<EditableField, string | number | null>>;

export const SECTION_OF_FIELD: Record<EditableField, 'identite' | 'coordonnees' | 'diplome' | 'engagement'> = {
  civilite: 'identite',
  nom: 'identite',
  prenom: 'identite',
  epouse: 'identite',
  date_naissance: 'identite',
  lieu_naissance: 'identite',
  genre: 'identite',
  statut_matrimonial: 'identite',
  nationalite: 'identite',
  specialite: 'identite',
  second_choix: 'identite',
  type_etude: 'identite',
  premiere_langue: 'identite',
  pays_origine: 'coordonnees',
  pays_residence: 'coordonnees',
  region: 'coordonnees',
  departement: 'coordonnees',
  adresse: 'coordonnees',
  ville_residence: 'coordonnees',
  indicatif1: 'coordonnees',
  telephone1: 'coordonnees',
  indicatif2: 'coordonnees',
  telephone2: 'coordonnees',
  email: 'coordonnees',
  diplome_obtenu: 'diplome',
  institut: 'diplome',
  specialite_diplome: 'diplome',
  annee_diplome: 'diplome',
  statut_actuel: 'diplome',
  fonction_actuelle: 'diplome',
  employeur: 'diplome',
  adresse_employeur: 'diplome',
  tel_employeur: 'diplome',
  moyen_connaissance: 'diplome',
  moyen_connaissance_detail: 'diplome',
  engagement_nom: 'engagement',
};
