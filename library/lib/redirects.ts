/**
 * Validation des redirections d'anciennes URL (fonctions pures, utilisables par le middleware edge).
 */

/** Chemin interne : `/x`, jamais `//hôte` ni `/\hôte` (hôte externe pour le navigateur). */
const INTERNAL_PATH = /^\/(?![/\\])/;

/**
 * Caractères de contrôle (C0 et DEL) : le parseur d'URL supprime tabulations et retours à la ligne,
 * si bien que `/\t/evil.com` deviendrait `//evil.com` une fois résolu.
 */
// eslint-disable-next-line no-control-regex
const CONTROL_CHARS = /[\u0000-\u001F\u007F]/;

/**
 * Cible de redirection sûre, résolue contre l'URL demandée ; `null` si elle sort du site.
 * Double garde : forme du chemin, puis origine identique une fois l'URL construite.
 */
export function safeRedirectTarget(to: unknown, base: URL | string): URL | null {
  if (typeof to !== 'string' || CONTROL_CHARS.test(to) || !INTERNAL_PATH.test(to)) return null;
  try {
    const origin = new URL(base).origin;
    const dest = new URL(to, base);
    return dest.origin === origin ? dest : null;
  } catch {
    return null;
  }
}

/**
 * Chemin soumis à l'API des redirections. Les anciennes URL Joomla `/index.php?option=…` n'ont de
 * sens qu'avec leur query string (stockée telle quelle) ; ailleurs, elle est ignorée.
 */
export function legacyLookupPath({ pathname, search }: { pathname: string; search: string }): string {
  return pathname === '/index.php' && search.length > 1 ? `${pathname}${search}` : pathname;
}
