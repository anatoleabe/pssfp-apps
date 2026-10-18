import type { ReactNode } from 'react';
import { ArrowUpRight, CloudOff } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { SearchForm } from '@/components/home/SearchForm';
import { MAIN_SITE_URL } from '@/components/layout/nav-config';

const SECONDARY_LINK =
  'inline-flex h-11 items-center justify-center gap-1.5 rounded-pssfp-button border border-pssfp-prune/30 bg-white px-5 font-ui text-sm font-medium text-pssfp-prune transition-colors hover:border-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';

interface ServiceUnavailableProps {
  /** Action principale (bouton « Réessayer » de la frontière d'erreur, lien de rechargement sinon). */
  action?: ReactNode;
}

/**
 * Page « catalogue indisponible » : reste utile (recherche, site principal, contact).
 * Composant sans état ni hook client : rendu par `app/error.tsx` comme par une page serveur.
 */
export function ServiceUnavailable({ action }: ServiceUnavailableProps): JSX.Element {
  const t = useTranslations('errorPage');
  return (
    <div data-testid="page-error" className="mx-auto max-w-3xl px-4 py-16 sm:px-6 md:py-24">
      <div role="alert">
        <p className="flex items-center gap-3 font-ui text-xs font-medium uppercase tracking-[0.18em] text-pssfp-or-dark">
          <CloudOff size={16} aria-hidden="true" />
          {t('eyebrow')}
        </p>
        <h1 className="mt-4 text-balance font-heading text-pssfp-h1 font-semibold text-pssfp-prune">{t('title')}</h1>
        <p className="mt-5 max-w-2xl text-pssfp-lead">{t('body')}</p>
      </div>

      <div className="mt-10 max-w-2xl">
        <p className="mb-3 font-ui text-sm text-pssfp-graphite-light">{t('searchHint')}</p>
        <SearchForm size="hero" id="error-search-q" />
      </div>

      <div className="mt-10 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
        {action}
        <a href={MAIN_SITE_URL} className={SECONDARY_LINK}>
          {t('mainSite')}
          <ArrowUpRight size={14} aria-hidden="true" />
        </a>
        <a href={`${MAIN_SITE_URL}/contact`} className={SECONDARY_LINK}>
          {t('contact')}
          <ArrowUpRight size={14} aria-hidden="true" />
        </a>
      </div>
    </div>
  );
}

/** Classes du bouton principal, partagées par le bouton « Réessayer » et le lien de repli. */
export const PRIMARY_ACTION =
  'inline-flex h-11 items-center justify-center gap-2 rounded-pssfp-button bg-pssfp-prune px-5 font-ui text-sm font-medium text-white shadow-pssfp-soft transition-colors hover:bg-pssfp-prune-dark active:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';
