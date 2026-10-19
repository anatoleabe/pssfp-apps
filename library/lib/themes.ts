import type { MotCle } from './api/types';

/** Racines du thésaurus, dans l'ordre d'affichage de `/themes`. */
export const THESAURUS_ROOTS = ['themes', 'institutions', 'types-de-textes'] as const;
export type ThesaurusRoot = (typeof THESAURUS_ROOTS)[number];

/**
 * Thèmes proposés en premier quand l'API ne fournit pas encore de comptes (ordre du brief :
 * budget, fiscalité, marchés publics, comptabilité, audit, décentralisation, économie publique,
 * politiques publiques). Avec des comptes, l'ordre suit le nombre de documents.
 */
const FEATURED_ORDER = [
  'gestion-budgetaire',
  'fiscalite',
  'marches-publics',
  'comptabilite-publique',
  'audit-et-controle',
  'decentralisation',
  'economie-publique',
  'politiques-publiques',
  'budget-programme',
  'finances-publiques',
];

export interface ThemeNode {
  slug: string;
  label: string;
  /** Nombre de documents, ou null si l'API ne le fournit pas. */
  count: number | null;
  children: ThemeNode[];
}

const byLabel = (a: { label: string }, b: { label: string }): number => a.label.localeCompare(b.label, 'fr');

/** Compte connu (l'API du plan 4 le fournit) ? */
export function hasCount(m: MotCle): m is MotCle & { documents_count: number } {
  return typeof m.documents_count === 'number';
}

/** Vrai si au moins un mot-clé porte un compte : l'API sait alors compter (un compte absent vaut 0). */
export function countsKnown(motsCles: readonly MotCle[]): boolean {
  return motsCles.some(hasCount);
}

/** Un mot-clé est affiché sauf s'il est connu pour n'avoir aucun document. */
function visible(m: MotCle, known: boolean): boolean {
  return known ? (m.documents_count ?? 0) > 0 : true;
}

/**
 * Arbre du thésaurus pour `/themes` : les trois racines dans l'ordre (seulement celles présentes),
 * enfants triés par libellé, niveau intermédiaire conservé (Institutions internationales → FMI…).
 * Avec des comptes, les mots-clés à 0 sont masqués (une racine vide disparaît) ; sans comptes,
 * tout est affiché sans nombre.
 */
export function buildThemeTree(motsCles: readonly MotCle[]): ThemeNode[] {
  const known = countsKnown(motsCles);
  const node = (m: MotCle, depth: number): ThemeNode | null => {
    const children = depth > 3 ? [] : motsCles.filter((c) => c.parent_slug === m.slug).sort(byLabel).flatMap((c) => node(c, depth + 1) ?? []);
    if (!visible(m, known) && children.length === 0) return null;
    return { slug: m.slug, label: m.label, count: known ? m.documents_count ?? 0 : null, children };
  };
  return THESAURUS_ROOTS.flatMap((slug) => {
    const root = motsCles.find((m) => m.slug === slug && m.parent_slug === null);
    const tree = root ? node(root, 0) : null;
    return tree && tree.children.length > 0 ? [tree] : [];
  });
}

/** Enfants directs visibles d'un mot-clé (sous-thèmes d'une page de thème), triés par libellé. */
export function childrenOf(motsCles: readonly MotCle[], slug: string): MotCle[] {
  const known = countsKnown(motsCles);
  return motsCles.filter((m) => m.parent_slug === slug && visible(m, known)).sort(byLabel);
}

/** Thèmes mis en avant sur l'accueil : enfants de « Thèmes », par nombre de documents si connu. */
export function featuredThemes(motsCles: readonly MotCle[], limit = 8): MotCle[] {
  const known = countsKnown(motsCles);
  const themes = motsCles.filter((m) => m.parent_slug === 'themes' && visible(m, known));
  const rank = (m: MotCle): number => {
    const i = FEATURED_ORDER.indexOf(m.slug);
    return i === -1 ? FEATURED_ORDER.length : i;
  };
  const sorted = [...themes].sort((a, b) =>
    known ? (b.documents_count ?? 0) - (a.documents_count ?? 0) || byLabel(a, b) : rank(a) - rank(b) || byLabel(a, b),
  );
  return sorted.slice(0, limit);
}

/**
 * Puces « Thèmes de la collection » (liste de `getMotsCles({ type })`) : mots-clés non racines avec au
 * moins un document du type, par nombre décroissant, `limit` au plus. Sans comptes (API antérieure),
 * aucune puce : rien ne dit quels mots-clés concernent la collection.
 */
export function collectionThemes(motsCles: readonly MotCle[], limit = 12): Array<MotCle & { documents_count: number }> {
  if (!countsKnown(motsCles)) return [];
  const roots = new Set<string>(THESAURUS_ROOTS);
  return motsCles
    .filter((m): m is MotCle & { documents_count: number } => hasCount(m) && m.documents_count > 0 && m.parent_slug !== null && !roots.has(m.slug))
    .filter((m) => !motsCles.some((c) => c.parent_slug === m.slug)) // niveaux intermédiaires : redondants avec leurs enfants
    .sort((a, b) => b.documents_count - a.documents_count || byLabel(a, b))
    .slice(0, limit);
}

/** Mots-clés feuilles d'une racine (ex. types de textes), avec au moins un document connu. */
export function countedChildren(motsCles: readonly MotCle[], parent: string): Array<MotCle & { documents_count: number }> {
  return motsCles
    .filter((m): m is MotCle & { documents_count: number } => m.parent_slug === parent && hasCount(m) && m.documents_count > 0)
    .sort((a, b) => b.documents_count - a.documents_count || byLabel(a, b));
}

/** Nombre de thèmes (enfants de « Thèmes ») à afficher : null si le thésaurus n'en contient aucun. */
export function themesCount(motsCles: readonly MotCle[]): number | null {
  const known = countsKnown(motsCles);
  const n = motsCles.filter((m) => m.parent_slug === 'themes' && visible(m, known)).length;
  return n > 0 ? n : null;
}
