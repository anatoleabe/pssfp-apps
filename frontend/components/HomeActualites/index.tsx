import Link from 'next/link';
import { ArrowRight, Calendar } from 'lucide-react';
import { getTranslations } from 'next-intl/server';

/**
 * Bloc « 3 actualités à la une » — refonte PR R.
 *
 * Cards avec image header gradient + animation reveal au hover, badge catégorie
 * en pill, footer avec read-more arrow animé.
 *
 * V1 : seed mock (3 articles placeholders) tant que la table `articles` /
 * Resource Filament `ArticleResource` n'est pas créée (cf. PR N).
 *
 * <!-- TODO replace MOCK_ARTICLES with fetch /v1/articles?featured=true (PR N) -->
 */
const MOCK_ARTICLES = [
  {
    slug: 'rentree-academique-2026',
    dateIso: '2026-09-20',
    categoryKey: 'catEvenement',
    titleKey: 'article1',
    excerptKey: 'article1Body',
    accent: 'violet' as const,
  },
  {
    slug: 'partenariat-fmi-2026',
    dateIso: '2026-04-15',
    categoryKey: 'catCooperation',
    titleKey: 'article2',
    excerptKey: 'article2Body',
    accent: 'or' as const,
  },
  {
    slug: 'soutenances-promotion-13',
    dateIso: '2026-02-10',
    categoryKey: 'catVie',
    titleKey: 'article3',
    excerptKey: 'article3Body',
    accent: 'lavande' as const,
  },
] as const;

function formatDateFr(iso: string): string {
  return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' }).format(
    new Date(iso),
  );
}

export async function HomeActualites(): Promise<JSX.Element> {
  const t = await getTranslations('home.actualites');

  const accentBg = {
    violet: 'bg-gradient-violet',
    or: 'bg-gradient-violet-or',
    lavande: 'bg-gradient-lavande-blanc',
  } as const;

  return (
    <section
      aria-labelledby="actualites-heading"
      data-testid="home-actualites"
      className="bg-white"
    >
      <div className="mx-auto max-w-7xl px-6 py-20 md:py-24">
        <header className="mb-12 flex flex-wrap items-end justify-between gap-4">
          <div className="max-w-2xl">
            <p className="pssfp-eyebrow">{t('eyebrow')}</p>
            <h2
              id="actualites-heading"
              className="mt-3 font-heading font-bold text-pssfp-h2 text-[#1A0A2E]"
            >
              {t('title')}
            </h2>
          </div>
          <Link
            href="/actualites"
            className="group inline-flex items-center gap-2 rounded-pssfp-button border border-[#EDE7F6] bg-white px-4 py-2.5 text-sm font-semibold text-[#6B2FA0] transition-all duration-200 hover:border-[#6B2FA0] hover:shadow-pssfp-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
          >
            {t('seeAll')}
            <ArrowRight
              size={14}
              aria-hidden="true"
              className="transition-transform duration-200 group-hover:translate-x-0.5"
            />
          </Link>
        </header>

        {/* Bandeau placeholder — à retirer quand PR N est mergée */}
        <p
          role="note"
          className="mb-8 rounded-pssfp-card border border-amber-300 bg-amber-50 p-4 text-xs leading-relaxed text-amber-900"
        >
          {t('placeholder')}
        </p>

        <ul className="grid gap-6 md:grid-cols-3">
          {MOCK_ARTICLES.map((article) => (
            <li key={article.slug}>
              <article className="group flex h-full flex-col overflow-hidden rounded-pssfp-card border border-[#EDE7F6] bg-white shadow-pssfp-soft transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 hover:border-[#9B59B6]/40 hover:shadow-pssfp-elevated">
                {/* Header image avec gradient + pattern */}
                <div
                  aria-hidden="true"
                  className={`relative h-48 overflow-hidden ${accentBg[article.accent]}`}
                >
                  {/* Pattern grille */}
                  <div
                    className="absolute inset-0 opacity-[0.08]"
                    style={{
                      backgroundImage:
                        'linear-gradient(white 1px, transparent 1px), linear-gradient(90deg, white 1px, transparent 1px)',
                      backgroundSize: '32px 32px',
                    }}
                  />
                  {/* Halo radial */}
                  <div
                    className="absolute inset-0 transition-transform duration-700 ease-pssfp-out-expo group-hover:scale-110"
                    style={{
                      background:
                        article.accent === 'lavande'
                          ? 'radial-gradient(circle at 70% 30%, rgba(107, 47, 160, 0.18) 0%, transparent 60%)'
                          : 'radial-gradient(circle at 70% 30%, rgba(255, 255, 255, 0.25) 0%, transparent 60%)',
                    }}
                  />
                </div>

                <div className="flex grow flex-col p-6">
                  <p className="flex flex-wrap items-center gap-2 text-xs">
                    <span className="inline-flex items-center gap-1 text-[#666]">
                      <Calendar size={12} aria-hidden="true" />
                      <time dateTime={article.dateIso}>{formatDateFr(article.dateIso)}</time>
                    </span>
                    <span
                      className="inline-flex items-center rounded-full bg-[#EDE7F6] px-2.5 py-1 text-xs font-semibold uppercase tracking-wider text-[#6B2FA0]"
                    >
                      {t(article.categoryKey)}
                    </span>
                  </p>
                  <h3 className="mt-4 grow font-heading text-pssfp-h3 font-bold leading-snug text-[#1A0A2E]">
                    {t(article.titleKey)}
                  </h3>
                  <p className="mt-3 text-sm leading-relaxed text-[#555]">
                    {t(article.excerptKey)}
                  </p>
                  <Link
                    href={`/actualites/${article.slug}`}
                    className="mt-5 inline-flex items-center gap-1.5 self-start rounded text-sm font-semibold text-[#6B2FA0] transition-all duration-200 hover:gap-2.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
                  >
                    {t('readMore')}
                    <ArrowRight
                      size={14}
                      aria-hidden="true"
                      className="transition-transform duration-200 group-hover:translate-x-0.5"
                    />
                  </Link>
                </div>
              </article>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
