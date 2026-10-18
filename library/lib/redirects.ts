/**
 * Validation des redirections d'anciennes URL (fonctions pures, utilisables par le middleware edge).
 */

/** Chemin interne : `/x`, jamais `//hôte` (hôte externe pour le navigateur). */
const INTERNAL_PATH = /^\/(?!\/)/;

/**
 * Caractères de contrôle (C0 et DEL) : le parseur d'URL supprime tabulations et retours à la ligne,
 * si bien que `/\t/evil.com` deviendrait `//evil.com` une fois résolu.
 */
// eslint-disable-next-line no-control-regex
const CONTROL_CHARS = /[\u0000-\u001F\u007F]/;

/**
 * Segment `.` ou `..`, y compris encodé (`%2e`, sans casse), suivi de `/`, `?`, `#` ou de la fin.
 * `new URL('/a/..//evil.com', base)` donne le chemin `//evil.com` (même origine !), que Next 14.2
 * relativise ensuite en `Location: //evil.com` : le navigateur part sur evil.com.
 * Même règle que `LibraryRedirect::isSafeTarget` et la contrainte CHECK de `library_redirects`.
 */
const DOT_SEGMENT = /(^|\/)(\.|%2e){1,2}([/?#]|$)/i;

/**
 * Cible de redirection sûre, résolue contre l'URL demandée ; `null` si elle peut sortir du site.
 * Triple garde : forme de la chaîne (contrôle, barre inverse, `//`, segments points), même origine
 * une fois l'URL construite, chemin normalisé ni `//…` ni `\`. La cible renvoyée est reconstruite
 * à partir du chemin et de la query normalisés (jamais la chaîne brute, jamais de fragment).
 */
export function safeRedirectTarget(to: unknown, base: URL | string): URL | null {
  if (typeof to !== 'string' || CONTROL_CHARS.test(to) || to.includes('\\') || !INTERNAL_PATH.test(to) || DOT_SEGMENT.test(to)) return null;
  try {
    const origin = new URL(base).origin;
    const dest = new URL(to, origin);
    if (dest.origin !== origin || dest.pathname.startsWith('//') || dest.pathname.includes('\\')) return null;
    return new URL(`${dest.pathname}${dest.search}`, origin);
  } catch {
    return null;
  }
}

/**
 * Chemin soumis à l'API des redirections. Les anciennes URL Joomla (`/index.php?option=…`,
 * `/index.php/component/abook/book?id=N`) n'ont de sens qu'avec leur query string (stockée telle
 * quelle) ; hors `/index.php`, elle est ignorée.
 */
export function legacyLookupPath({ pathname, search }: { pathname: string; search: string }): string {
  return (pathname === '/index.php' || pathname.startsWith('/index.php/')) && search.length > 1 ? `${pathname}${search}` : pathname;
}
