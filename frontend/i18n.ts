import { getRequestConfig } from 'next-intl/server';

export const SUPPORTED_LOCALES = ['fr'] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];
export const DEFAULT_LOCALE: SupportedLocale = 'fr';

export default getRequestConfig(async () => {
  const locale: SupportedLocale = DEFAULT_LOCALE;
  const messages = (await import(`./messages/${locale}.json`)).default;

  return {
    locale,
    messages,
    timeZone: 'Africa/Douala',
    now: new Date(),
  };
});
