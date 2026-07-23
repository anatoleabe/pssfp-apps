import type { StatutActuel } from '@/lib/api/types';

export const STATUT_ACTUEL_OPTIONS: ReadonlyArray<{ value: StatutActuel; label: string }> = [
  { value: 'Etudiant', label: 'Étudiant(e)' },
  { value: 'Sans-emploi', label: 'Sans emploi / en recherche d’emploi' },
  { value: 'Fonctionnaire', label: 'Fonctionnaire titulaire' },
  { value: 'Contractuel-Etat', label: 'Agent contractuel de l’État' },
  { value: 'Etablissement-public', label: 'Agent d’un établissement public' },
  { value: 'Entreprise-publique', label: 'Salarié(e) d’une entreprise publique' },
  { value: 'Prive', label: 'Salarié(e) du secteur privé' },
  { value: 'Independant', label: 'Indépendant(e) / profession libérale' },
  { value: 'ONG-International', label: 'ONG / organisation internationale' },
  { value: 'Autre', label: 'Autre situation professionnelle' },
];

export const MOYENS_CONNAISSANCE = [
  'Site officiel du PSSFP',
  'Moteur de recherche (Google, Bing…)',
  'Facebook',
  'LinkedIn',
  'YouTube',
  'Autre réseau social',
  'Courriel ou lettre d’information du PSSFP',
  'Administration ou employeur',
  'Université ou établissement d’enseignement',
  'Ancien auditeur ou diplômé du PSSFP',
  'Collègue, ami ou membre de la famille',
  'Salon, conférence ou événement professionnel',
  'Presse, radio ou télévision',
  'Autre',
] as const;

export function isPublicEmploymentStatus(status: string): boolean {
  return [
    'Fonctionnaire',
    'Contractuel-Etat',
    'Etablissement-public',
    'Entreprise-publique',
    'Fonctionnaire-Contractuel',
  ].includes(status);
}

export function needsEmployer(status: string): boolean {
  return status !== '' && status !== 'Etudiant' && status !== 'Sans-emploi';
}
