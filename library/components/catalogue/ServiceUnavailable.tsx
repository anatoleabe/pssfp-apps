import type { ReactNode } from 'react';
import { ArrowUpRight, CloudOff } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { Eyebrow } from '@/components/home/Eyebrow';
import { SearchForm } from '@/components/home/SearchForm';
import { BandDecor } from '@/components/layout/PageHeader';
import { MAIN_SITE_URL } from '@/components/layout/nav-config';

const SECONDARY_LINK =
  'inline-flex h-11 items-center justify-center gap-1.5 rounded-pssfp-button border border-pssfp-prune/30 bg-white px-5 font-ui text-sm font-medium text-pssfp-prune transition-colors hover:border-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';

interface ServiceUnavailableProps {
  /** Action principale (bouton « Réessayer » de la frontière d'erreur, lien de rechargement sinon). */
  action?: ReactNode;
}

/**
 * Page « catalogue indisponible » : reste utile (recherche, site principal, contact).
 * Bandeau prune de la charte (comme les pages intérieures), puis les sorties sur fond clair.
 * Composant sans état ni hook client : rendu par `app/error.tsx` comme par une page serveur.
 */
export function ServiceUnavailable({ action }: ServiceUnavailableProps): JSX.Element {
  const t = useTranslations('errorPage');
  return (
    <div data-testid="page-error">
      <section className="relative isolate overflow-hidden bg-pssfp-prune-deep text-white">
        <BandDecor />
        <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 md:py-20">
          <div role="alert" className="max-w-3xl">
            <Eyebrow tone="light">
              <span className="inline-flex items-center gap-2">
                <CloudOff size={14} aria-hidden="true" />
                {t('eyebrow')}
              </span>
            </Eyebrow>
            <h1 className="mt-4 text-balance font-heading text-pssfp-h1 font-semibold">{t('title')}</h1>
            <p className="mt-5 max-w-2xl text-pssfp-lead text-white/85">{t('body')}</p>
          </div>
          <div className="mt-10 max-w-2xl">
            <p className="mb-3 font-ui text-sm text-white/80">{t('searchHint')}</p>
            <SearchForm size="hero" id="error-search-q" tone="dark" />
          </div>
        </div>
      </section>

      <div className="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-10 sm:flex-row sm:flex-wrap sm:px-6">
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
