/*
 * Nettoyage des valeurs insérées dans le gabarit de citation (messages `citation.*`).
 * Le gabarit ajoute lui-même « . » après le titre : un titre qui finit déjà par un point
 * donnerait « … ad et.. Article ».
 */

/** Ponctuation finale retirée du titre (le point d'interrogation et d'exclamation restent : ils portent le sens). */
const TITLE_TRAILING = /[\s.,;:·—–-]+$/u;
/** Séparateurs finaux retirés d'un auteur ; le point d'une initiale (« Dupont J. ») est conservé. */
const AUTHOR_TRAILING = /[\s,;:·—–-]+$/u;
/** Points répétés en fin d'auteur (« J.. ») ramenés à un seul. */
const AUTHOR_DOTS = /\.{2,}$/u;

export function citationTitle(title: string): string {
  return title.trim().replace(TITLE_TRAILING, '');
}

export function citationAuthors(authors: readonly string[]): string {
  return authors
    .map((author) => author.trim().replace(AUTHOR_TRAILING, '').replace(AUTHOR_DOTS, '.'))
    .filter((author) => author.length > 0)
    .join(', ');
}
