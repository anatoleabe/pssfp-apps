import type { Diplome, EmployeurPublicGroup, Pays, Specialite, UniversitePays } from './types';

/**
 * Données de fallback utilisées par les Server Components quand le backend
 * est temporairement indisponible (incident SMTP, déploiement, etc.).
 *
 * Permet au wizard d'inscription de rester utilisable en mode dégradé,
 * et garantit que la CI Playwright (qui ne démarre pas le backend Laravel
 * dans le job candidature) trouve quand même les options dans les selects.
 *
 * Les listes complètes sont sourcées depuis l'API quand elle répond. Si
 * elle ne répond pas, on retourne juste un sous-ensemble représentatif.
 */

export const FALLBACK_SPECIALITES: ReadonlyArray<Specialite> = [
  { slug: 'fiscalite-finance-comptabilite-publique', label: 'Fiscalité - Finance - Comptabilité Publique' },
  { slug: 'audit-controle-gestion-publique', label: 'Audit - Contrôle de Gestion Publique' },
  { slug: 'gestion-marches-publics-partenariats', label: 'Gestion des Marchés Publics et Partenariats' },
  { slug: 'gestion-administrative-rh-secteur-public', label: 'Gestion Administrative et RH du Secteur Public' },
  { slug: 'finance-publique-collectivites-territoriales', label: 'Finance Publique des Collectivités Territoriales' },
];

export const FALLBACK_PAYS: ReadonlyArray<Pays> = [
  { code_iso: 'CM', nom: 'Cameroun', indicatif: '+237' },
  { code_iso: 'FR', nom: 'France', indicatif: '+33' },
  { code_iso: 'CA', nom: 'Canada', indicatif: '+1' },
  { code_iso: 'BE', nom: 'Belgique', indicatif: '+32' },
  { code_iso: 'CI', nom: "Côte d'Ivoire", indicatif: '+225' },
  { code_iso: 'SN', nom: 'Sénégal', indicatif: '+221' },
  { code_iso: 'TD', nom: 'Tchad', indicatif: '+235' },
  { code_iso: 'CG', nom: 'Congo', indicatif: '+242' },
  { code_iso: 'CD', nom: 'République Démocratique du Congo', indicatif: '+243' },
  { code_iso: 'GA', nom: 'Gabon', indicatif: '+241' },
  { code_iso: 'GQ', nom: 'Guinée Équatoriale', indicatif: '+240' },
  { code_iso: 'CF', nom: 'République Centrafricaine', indicatif: '+236' },
  { code_iso: 'BJ', nom: 'Bénin', indicatif: '+229' },
  { code_iso: 'TG', nom: 'Togo', indicatif: '+228' },
  { code_iso: 'BF', nom: 'Burkina Faso', indicatif: '+226' },
  { code_iso: 'ML', nom: 'Mali', indicatif: '+223' },
  { code_iso: 'MA', nom: 'Maroc', indicatif: '+212' },
  { code_iso: 'DZ', nom: 'Algérie', indicatif: '+213' },
  { code_iso: 'TN', nom: 'Tunisie', indicatif: '+216' },
  { code_iso: 'US', nom: 'États-Unis', indicatif: '+1' },
  { code_iso: 'GB', nom: 'Royaume-Uni', indicatif: '+44' },
];

export const FALLBACK_DIPLOMES: ReadonlyArray<Diplome> = [
  { slug: 'baccalaureat', label: 'Baccalauréat' },
  { slug: 'bts-dut', label: 'BTS / DUT' },
  { slug: 'licence', label: 'Licence' },
  { slug: 'licence-professionnelle', label: 'Licence professionnelle' },
  { slug: 'maitrise', label: 'Maîtrise' },
  { slug: 'master', label: 'Master' },
  { slug: 'ingenieur', label: "Diplôme d'Ingénieur" },
  { slug: 'doctorat', label: 'Doctorat' },
];

export const FALLBACK_UNIVERSITES: ReadonlyArray<UniversitePays> = [
  {
    pays: 'Cameroun',
    universites: [
      'Université de Yaoundé I',
      'Université de Yaoundé II (Soa)',
      'Université de Douala',
      'Université de Dschang',
      'Université de Ngaoundéré',
      'Université de Buea',
      'Université de Bamenda',
      'Université de Maroua',
      'Université de Bertoua',
      'Université d’Ebolowa',
      'Université de Garoua',
    ],
  },
];

export const FALLBACK_EMPLOYEURS_PUBLICS: ReadonlyArray<EmployeurPublicGroup> = [
  {
    categorie: 'Institutions et administrations centrales',
    employeurs: [
      'Présidence de la République',
      'Services du Premier ministre',
      'Ministère des Finances (MINFI)',
      'Ministère de l’Économie, de la Planification et de l’Aménagement du territoire (MINEPAT)',
      'Ministère de la Fonction publique et de la Réforme administrative (MINFOPRA)',
      'Ministère de la Décentralisation et du Développement local (MINDDEVEL)',
      'Ministère de la Santé publique (MINSANTE)',
      'Ministère de l’Enseignement supérieur (MINESUP)',
    ],
  },
  {
    categorie: 'Entreprises et établissements publics',
    employeurs: [
      'Caisse Nationale de Prévoyance Sociale (CNPS)',
      'Cameroon Telecommunications (CAMTEL)',
      'Cameroon Water Utilities Corporation (CAMWATER)',
      'Cameroun Airlines Corporation (CAMAIR-CO)',
      'Fonds Spécial d’Équipement et d’Intervention Intercommunale (FEICOM)',
      'Office de Radiodiffusion Télévision Camerounaise (CRTV)',
      'Port Autonome de Douala (PAD)',
      'Port Autonome de Kribi (PAK)',
      'Société Nationale de Raffinage (SONARA)',
    ],
  },
  {
    categorie: 'Universités d’État',
    employeurs: [
      'Université de Bertoua',
      'Université d’Ebolowa',
      'Université de Garoua',
      'Université de Yaoundé I',
      'Université de Yaoundé II (Soa)',
    ],
  },
];
