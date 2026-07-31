'use client';

import { Globe } from 'lucide-react';
import { useLocale, useTranslations } from 'next-intl';
import { useSearchParams } from 'next/navigation';
import { useTransition } from 'react';

import { LOCALE_LABELS, SUPPORTED_LOCALES, type SupportedLocale } from '@/i18n';
import { usePathname, useRouter } from '@/navigation';

/**
 * Bascule FR/EN conservant la page courante et ses paramètres de requête.
 *
 * Rendu en groupe de boutons plutôt qu'en menu déroulant : à deux langues, un
 * select ajoute un clic et un piège d'accessibilité pour rien. La langue active
 * est annoncée via `aria-current` et le choix est mémorisé par next-intl dans
 * le cookie NEXT_LOCALE, qui prime ensuite sur l'en-tête du navigateur.
 */
export function LanguageSwitcher(): JSX.Element {
  const activeLocale = useLocale();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const router = useRouter();
  const [isPending, startTransition] = useTransition();
  const t = useTranslations('language');

  const query = searchParams.toString();
  const target = query ? `${pathname}?${query}` : pathname;

  function switchTo(locale: SupportedLocale): void {
    if (locale === activeLocale) return;

    startTransition(() => {
      // `replace` et non `push` : la bascule de langue ne doit pas empiler une
      // entrée d'historique, sans quoi le bouton Retour renverrait dans l'autre
      // langue au lieu de la page précédente.
      router.replace(target, { locale, scroll: false });
    });
  }

  return (
    <div
      className="flex items-center gap-0.5 rounded-pssfp-button border border-[var(--pssfp-border)] bg-white p-0.5"
      data-testid="language-switcher"
    >
      <Globe
        size={14}
        aria-hidden="true"
        className="ml-1.5 mr-0.5 hidden shrink-0 text-[#6B6B6B] sm:inline"
      />
      <span className="sr-only" id="language-switcher-label">
        {t('label')}
      </span>
      <ul className="flex items-center gap-0.5" aria-labelledby="language-switcher-label">
        {SUPPORTED_LOCALES.map((locale) => {
          const isActive = locale === activeLocale;

          return (
            <li key={locale}>
              <button
                type="button"
                lang={locale}
                disabled={isPending}
                aria-current={isActive ? 'true' : undefined}
                data-testid={`language-${locale}`}
                onClick={() => switchTo(locale)}
                className={`inline-flex h-7 items-center rounded-[6px] px-2.5 font-ui text-xs font-semibold uppercase tracking-wide transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-1 disabled:opacity-60 ${
                  isActive
                    ? 'bg-[#4A2E67] text-white'
                    : 'text-[#5A5A5A] hover:bg-[var(--pssfp-primary-soft)] hover:text-pssfp-prune'
                }`}
              >
                <span aria-hidden="true">{locale}</span>
                <span className="sr-only">{LOCALE_LABELS[locale]}</span>
              </button>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
