import { DOCUMENT_TYPES, type AccessReason, type DocumentDetail, type DocumentSummary } from '@/lib/api/types';

/*
 * Données entièrement fictives (titres, auteurs, identifiants) pour la page
 * de démonstration /dev/composants — aucune donnée réelle.
 */
const base: DocumentSummary = {
  uuid: '00000000-0000-4000-8000-000000000001',
  slug: 'demo-memoire-libre',
  type: 'memoire',
  type_label: 'Mémoire',
  title: 'Budget-programme et pilotage de la performance dans une collectivité fictive',
  subtitle: null,
  year: 2024,
  language: 'fr',
  specialite: null,
  promotion: 12,
  authors: ['A. Exemple', 'B. Fictif', 'C. Démonstration'],
  mots_cles: [],
  cover_url: null,
  access_mode: 'libre',
  price_xaf: null,
  published_at: '2024-06-01T00:00:00Z',
};

export const DEMO_LIBRE: DocumentSummary = base;

export const DEMO_RESERVE: DocumentSummary = {
  ...base,
  uuid: '00000000-0000-4000-8000-000000000002',
  slug: 'demo-cours-reserve',
  type: 'cours',
  type_label: 'Cours',
  title: 'Support fictif — Finances locales, unité 4',
  year: 2025,
  promotion: null,
  authors: ['D. Enseignant'],
  access_mode: 'reserve',
};

export const DEMO_PAYANT: DocumentSummary = {
  ...base,
  uuid: '00000000-0000-4000-8000-000000000003',
  slug: 'demo-ouvrage-payant',
  type: 'ouvrage_numerique',
  type_label: 'Ouvrage numérique',
  title: 'Manuel fictif de comptabilité publique',
  subtitle: 'Édition de démonstration',
  year: 2023,
  promotion: null,
  authors: ['E. Auteur', 'F. Coauteur'],
  access_mode: 'payant',
  price_xaf: 2500,
};

export const DEMO_COUVERTURE: DocumentSummary = {
  ...base,
  uuid: '00000000-0000-4000-8000-000000000004',
  slug: 'demo-these-couverture',
  type: 'these',
  type_label: 'Thèse',
  title: 'Thèse fictive avec couverture illustrée',
  promotion: null,
  authors: ['G. Doctorant'],
  cover_url: '/logos/pssfp.png',
};

/** Titre piégé : seul le <mark> doit survivre au nettoyage. */
export const DEMO_TRAP_TITLE = '<img src=x onerror=alert(1)><mark>a</mark>';

export const DEMO_SURLIGNAGE: DocumentSummary = {
  ...base,
  uuid: '00000000-0000-4000-8000-000000000005',
  slug: 'demo-surlignage',
  type: 'texte_loi',
  type_label: 'Texte de loi',
  title: DEMO_TRAP_TITLE,
  promotion: null,
  authors: [],
};

export const DEMO_ROW: DocumentSummary = {
  ...base,
  uuid: '00000000-0000-4000-8000-000000000006',
  slug: 'demo-rapport-ligne',
  type: 'rapport_stage',
  type_label: 'Rapport de stage',
  title: 'Rapport de stage fictif sur la chaîne de la dépense',
  authors: ['H. Stagiaire'],
};

/** Citation : titre qui finit par un point, initiale en fin d'auteur (aucun « .. » attendu). */
export const DEMO_CITATION_POINT: DocumentSummary = {
  ...base,
  uuid: '00000000-0000-4000-8000-000000000007',
  slug: 'demo-citation-point',
  type: 'article_scientifique',
  type_label: 'Article scientifique',
  title: 'Article fictif dont le titre finit par un point.',
  year: 2019,
  authors: ['Exemple A.', 'Fictif B.,'],
};

/** Une couverture typographique par type, pour contrôler couleurs et contrastes. */
export const DEMO_COVERS: DocumentSummary[] = DOCUMENT_TYPES.map((type, i) => ({
  ...base,
  uuid: `00000000-0000-4000-8000-0000000001${String(i).padStart(2, '0')}`,
  slug: `demo-couverture-${type}`,
  type,
  title: 'Titre de démonstration pour la couverture',
}));

const detail = (doc: DocumentSummary, reason: AccessReason, extra: Partial<DocumentDetail> = {}): DocumentDetail => ({
  ...doc,
  abstract: null,
  journal: null,
  doi: null,
  isbn: null,
  pages_count: null,
  file_size_bytes: null,
  external_url: null,
  views_count: 0,
  access: { allowed: reason === 'public' || reason === 'external', reason },
  ...extra,
});

export const DEMO_ACTIONS: DocumentDetail[] = [
  detail(DEMO_LIBRE, 'public', { file_size_bytes: Math.round(2.4 * 1024 * 1024) }),
  detail({ ...DEMO_LIBRE, uuid: '00000000-0000-4000-8000-000000000007', slug: 'demo-lien-externe' }, 'external', {
    external_url: 'https://example.org/document-fictif',
  }),
  detail(DEMO_RESERVE, 'reserved'),
  detail(DEMO_PAYANT, 'paid'),
];
