/** Liens de navigation partagés par l'en-tête, le tiroir mobile et le pied de page. */
export interface NavItem {
  key: 'home' | 'collections' | 'latest' | 'themes' | 'about';
  href: string;
}

export const NAV_ITEMS: readonly NavItem[] = [
  { key: 'home', href: '/' },
  { key: 'collections', href: '/#collections' },
  { key: 'latest', href: '/#nouveautes' },
  { key: 'themes', href: '/themes' },
  { key: 'about', href: '/a-propos' },
];

/** Page de recherche : bouton dédié de l'en-tête (hors des cinq rubriques). */
export const SEARCH_HREF = '/recherche';

/** URL du site principal, sans barre oblique finale (les chemins s'y ajoutent). */
export const MAIN_SITE_URL = (process.env.NEXT_PUBLIC_MAIN_SITE_URL || 'https://pssfp.org').replace(/\/+$/, '');
/** Plateforme FOAD : même valeur par défaut que le site principal. */
export const FOAD_URL = (process.env.NEXT_PUBLIC_FOAD_URL || 'https://foad.pssfp.org').replace(/\/+$/, '');

/**
 * Un lien d'ancre (`/#…`) n'est jamais « page courante » ; l'accueil ne l'est que sur `/` exactement.
 */
export function isNavActive(pathname: string, href: string): boolean {
  if (href.includes('#')) return false;
  if (href === '/') return pathname === '/';
  return pathname === href || pathname.startsWith(`${href}/`);
}
