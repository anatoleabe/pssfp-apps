import type { Metadata } from 'next';
import Link from 'next/link';
import { ArrowRight, ArrowUpRight, Mail, MapPin } from 'lucide-react';
import { getTranslations } from 'next-intl/server';
import { AccessBadge } from '@/components/document/AccessBadge';
import { Eyebrow } from '@/components/home/Eyebrow';
import { Breadcrumbs } from '@/components/layout/Breadcrumbs';
import { MAIN_SITE_URL } from '@/components/layout/nav-config';
import { ACCESS_MODES } from '@/lib/api/types';

/** Page fixe : aucune donnée de l'API, générée au build. */
export const dynamic = 'force-static';

const HOLDINGS = ['travaux', 'textes', 'ouvrages', 'cours'] as const;

const PRIMARY_LINK =
  'inline-flex h-11 items-center justify-center gap-2 rounded-pssfp-button bg-pssfp-prune px-5 font-ui text-sm font-medium text-white shadow-pssfp-soft transition-colors hover:bg-pssfp-prune-dark active:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2';
const TEXT_LINK =
  'inline-flex items-center gap-1.5 rounded-sm font-ui text-sm font-medium text-pssfp-prune underline-offset-4 transition-colors hover:text-pssfp-prune-dark hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('about');
  return {
    title: t('metaTitle'),
    description: t('metaDescription'),
    alternates: { canonical: '/a-propos' },
    openGraph: { title: t('title'), description: t('metaDescription'), url: '/a-propos', type: 'website', locale: 'fr_FR' },
  };
}

/** À propos : présentation du fonds, règles d'accès, contact (lien vers le site principal). */
export default async function AboutPage(): Promise<JSX.Element> {
  const t = await getTranslations('about');
  const tCrumbs = await getTranslations('breadcrumbs');
  const tFooter = await getTranslations('footer');
  const email = tFooter('contactEmail');

  return (
    <>
      <section aria-labelledby="about-title" className="relative isolate overflow-hidden border-b border-pssfp-lavande/30">
        <div aria-hidden="true" className="pointer-events-none absolute -right-40 -top-48 -z-10 h-[30rem] w-[30rem] rounded-full bg-[radial-gradient(closest-side,rgba(165,146,189,0.26),transparent)]" />
        <div className="mx-auto max-w-7xl px-4 pb-12 pt-8 sm:px-6 sm:pb-16 lg:pt-10">
          <Breadcrumbs items={[{ href: '/', label: tCrumbs('home') }, { label: t('metaTitle') }]} />
          <div className="mt-8 max-w-3xl">
            <Eyebrow tone="or">{t('eyebrow')}</Eyebrow>
            <h1 id="about-title" className="mt-4 text-balance font-heading text-pssfp-h1 font-semibold text-pssfp-prune lg:text-[4rem] lg:leading-none">
              {t('title')}
            </h1>
            <p className="mt-6 text-pssfp-lead text-pssfp-graphite">{t('lead')}</p>
          </div>
        </div>
      </section>

      <section aria-labelledby="about-presentation" className="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] lg:gap-16 lg:py-20">
        <div className="min-w-0">
          <h2 id="about-presentation" className="text-balance font-heading text-pssfp-h2 font-semibold text-pssfp-prune">
            {t('presentationTitle')}
          </h2>
          <div className="mt-6 max-w-2xl space-y-4 leading-relaxed">
            <p>{t('presentationBody')}</p>
            <p>{t('presentationBody2')}</p>
          </div>
          <Link href="/#collections" className={`mt-8 ${TEXT_LINK}`}>
            {t('browse')}
            <ArrowRight size={16} aria-hidden="true" />
          </Link>
        </div>
        <aside aria-labelledby="about-holdings" className="min-w-0 self-start rounded-pssfp-card border border-pssfp-lavande/30 bg-white p-6 shadow-pssfp-soft sm:p-8">
          <h3 id="about-holdings" className="font-ui text-xs font-medium uppercase tracking-[0.18em] text-pssfp-bleu-petrole">
            {t('holdingsTitle')}
          </h3>
          <ul className="mt-5 divide-y divide-pssfp-lavande/30">
            {HOLDINGS.map((key) => (
              <li key={key} className="flex gap-3 py-3 first:pt-0 last:pb-0">
                <span aria-hidden="true" className="mt-2.5 h-px w-4 shrink-0 bg-pssfp-or" />
                <span>{t(`holdings.${key}`)}</span>
              </li>
            ))}
          </ul>
        </aside>
      </section>

      <section aria-labelledby="about-access" className="border-y border-pssfp-lavande/30 bg-white">
        <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:py-20">
          <div className="max-w-2xl">
            <h2 id="about-access" className="font-heading text-pssfp-h2 font-semibold text-pssfp-prune">
              {t('accessTitle')}
            </h2>
            <p className="mt-4 leading-relaxed">{t('accessIntro')}</p>
          </div>
          <ul data-testid="about-access-rules" className="mt-10 grid gap-4 md:grid-cols-3 md:gap-6">
            {ACCESS_MODES.map((mode) => (
              <li key={mode} className="flex flex-col gap-4 rounded-pssfp-card border border-pssfp-lavande/30 bg-pssfp-ivoire p-6">
                <div className="flex flex-wrap items-center gap-2">
                  <AccessBadge mode={mode} />
                  {mode === 'payant' && (
                    <span className="rounded border border-pssfp-or-dark/40 px-2 py-0.5 font-ui text-xs font-medium text-pssfp-or-dark">{t('rules.soon')}</span>
                  )}
                </div>
                <p className="leading-relaxed">{t(`rules.${mode}`)}</p>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section aria-labelledby="about-contact" data-testid="about-contact" className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:py-20">
        <div className="grid gap-8 border-l-2 border-pssfp-or pl-6 sm:pl-8 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] lg:items-end lg:gap-16">
          <div className="min-w-0">
            <h2 id="about-contact" className="text-balance font-heading text-pssfp-h2 font-semibold text-pssfp-prune">
              {t('contactTitle')}
            </h2>
            <p className="mt-4 max-w-2xl leading-relaxed">{t('contactBody')}</p>
            <ul className="mt-6 space-y-3 text-sm">
              <li className="flex items-start gap-2">
                <MapPin size={16} aria-hidden="true" className="mt-0.5 shrink-0 text-pssfp-bleu-petrole" />
                <span>{tFooter('contactAddress')}</span>
              </li>
              <li className="flex items-start gap-2">
                <Mail size={16} aria-hidden="true" className="mt-0.5 shrink-0 text-pssfp-bleu-petrole" />
                <a href={`mailto:${email}`} className={TEXT_LINK}>
                  {email}
                </a>
              </li>
            </ul>
          </div>
          <div>
            <a href={`${MAIN_SITE_URL}/contact`} className={PRIMARY_LINK}>
              {t('contactPage')}
              <ArrowUpRight size={16} aria-hidden="true" />
            </a>
          </div>
        </div>
      </section>
    </>
  );
}
