import { getRequestConfig } from 'next-intl/server';

export const SUPPORTED_LOCALES = ['fr'] as const;
export const DEFAULT_LOCALE = 'fr' as const;

export default getRequestConfig(async () => {
  const locale = DEFAULT_LOCALE;
  const messages = (await import(`./messages/${locale}.json`)).default;
  return {
    locale,
    messages,
    timeZone: 'Africa/Douala',
    now: new Date(),
  };
});
