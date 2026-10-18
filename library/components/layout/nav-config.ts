/** Liens de navigation partagés par l'en-tête, le tiroir mobile et le pied de page. */
export interface NavItem {
  key: 'collections' | 'search' | 'about';
  href: string;
}

export const NAV_ITEMS: readonly NavItem[] = [
  { key: 'collections', href: '/#collections' },
  { key: 'search', href: '/recherche' },
  { key: 'about', href: '/a-propos' },
];

/** URL du site principal, sans barre oblique finale (les chemins s'y ajoutent). */
export const MAIN_SITE_URL = (process.env.NEXT_PUBLIC_MAIN_SITE_URL || 'https://pssfp.org').replace(/\/+$/, '');
export const FOAD_URL = process.env.NEXT_PUBLIC_FOAD_URL;

/** Un lien d'ancre (`/#…`) n'est jamais « page courante ». */
export function isNavActive(pathname: string, href: string): boolean {
  if (href.includes('#')) return false;
  return pathname === href || pathname.startsWith(`${href}/`);
}
