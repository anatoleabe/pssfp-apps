import { createSharedPathnamesNavigation } from 'next-intl/navigation';

import { SUPPORTED_LOCALES } from './i18n';

/**
 * `as-needed` : le français ne porte aucun préfixe (/dossier), l'anglais oui
 * (/en/dossier). Les URLs françaises déjà diffusées aux candidats restent donc
 * valides — condition posée pour intervenir pendant la campagne.
 */
export const localePrefix = 'as-needed' as const;

/**
 * Remplaçants localisés de `next/link` et `next/navigation`. Toute navigation
 * interne doit passer par eux : un `next/link` brut ferait retomber un visiteur
 * anglophone en français au premier clic.
 */
const shared = createSharedPathnamesNavigation({
  locales: SUPPORTED_LOCALES,
  localePrefix,
});

export const { Link, usePathname, useRouter } = shared;

/**
 * Redirection localisée retypée en `never`.
 *
 * `next/navigation.redirect` est typé `never` : TypeScript sait que le flux
 * s'arrête et affine les types en aval (`if (!token) redirect(...)` suffit à
 * rendre `token` non-nul ensuite). next-intl le type `void`, ce qui casserait
 * ce raisonnement dans une vingtaine de fichiers. Sous le capot next-intl
 * délègue bien à `next/navigation`, qui lève `NEXT_REDIRECT` : la ligne après
 * l'appel est donc réellement inatteignable.
 */
export function redirect(pathname: string): never {
  shared.redirect(pathname);

  throw new Error(`Redirection vers ${pathname} non interceptée`);
}
