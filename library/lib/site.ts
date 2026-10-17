const DEFAULT_SITE_URL = 'https://bibliotheque.pssfp.org';

/** URL canonique de la bibliothèque ; valeur par défaut si la variable est absente ou invalide. */
export function siteUrl(): URL {
  try {
    return new URL(process.env.NEXT_PUBLIC_SITE_URL || DEFAULT_SITE_URL);
  } catch {
    return new URL(DEFAULT_SITE_URL);
  }
}
