import { notFound } from 'next/navigation';
import { getRequestConfig } from 'next-intl/server';

/**
 * Locales servies par l'app candidature (ADR-0006).
 *
 * `fr` reste la langue source et n'a pas de préfixe d'URL : toutes les adresses
 * existantes (/login, /dossier, /inscription) sont inchangées, ce qui est
 * indispensable en pleine campagne P14. L'anglais vit sous /en/...
 */
export const SUPPORTED_LOCALES = ['fr', 'en'] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];
export const DEFAULT_LOCALE: SupportedLocale = 'fr';

/** Libellés du sélecteur de langue, dans leur propre langue. */
export const LOCALE_LABELS: Record<SupportedLocale, string> = {
  fr: 'Français',
  en: 'English',
};

export function isSupportedLocale(value: unknown): value is SupportedLocale {
  return typeof value === 'string' && (SUPPORTED_LOCALES as readonly string[]).includes(value);
}

export default getRequestConfig(async ({ locale }) => {
  // Une locale inconnue dans l'URL (/de/dossier) doit tomber en 404 plutôt que
  // de faire échouer l'import du fichier de messages avec une 500.
  if (!isSupportedLocale(locale)) {
    notFound();
  }

  return {
    locale,
    messages: (await import(`./messages/${locale}.json`)).default,
    timeZone: 'Africa/Douala',
    now: new Date(),
  };
});
