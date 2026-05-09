import Link from 'next/link';
import { ArrowRight, Calendar } from 'lucide-react';
import { getTranslations } from 'next-intl/server';

/**
 * Bloc « 3 actualités à la une ».
 *
 * V1 : seed mock (3 articles placeholders) tant que la table `articles` /
 * Resource Filament `ArticleResource` n'est pas créée (cf. PR N).
 *
 * Quand l'API `/v1/articles?featured=true&limit=3` répondra, remplacer le
 * MOCK_ARTICLES par un fetch SSR avec ISR 5 min.
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
  },
  {
    slug: 'partenariat-fmi-2026',
    dateIso: '2026-04-15',
    categoryKey: 'catCooperation',
    titleKey: 'article2',
    excerptKey: 'article2Body',
  },
  {
    slug: 'soutenances-promotion-13',
    dateIso: '2026-02-10',
    categoryKey: 'catVie',
    titleKey: 'article3',
    excerptKey: 'article3Body',
  },
] as const;

function formatDateFr(iso: string): string {
  return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' }).format(
    new Date(iso),
  );
}

export async function HomeActualites(): Promise<JSX.Element> {
  const t = await getTranslations('home.actualites');
  return (
    <section
      aria-labelledby="actualites-heading"
      data-testid="home-actualites"
      className="bg-white"
    >
      <div className="mx-auto max-w-7xl px-6 py-16 md:py-20">
        <header className="mb-10 flex flex-wrap items-end justify-between gap-4">
          <div className="max-w-2xl">
            <p className="font-ui text-sm uppercase tracking-widest text-[#C9A227]">
              {t('eyebrow')}
            </p>
            <h2
              id="actualites-heading"
              className="mt-2 font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl"
            >
              {t('title')}
            </h2>
          </div>
          <Link
            href="/actualites"
            className="inline-flex items-center gap-1 text-sm font-medium text-[#6B2FA0] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded"
          >
            {t('seeAll')}
            <ArrowRight size={14} aria-hidden="true" />
          </Link>
        </header>

        {/* Bandeau placeholder visible — à retirer quand PR N est mergée */}
        <p
          role="note"
          className="mb-6 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900"
        >
          {t('placeholder')}
        </p>

        <ul className="grid gap-6 md:grid-cols-3">
          {MOCK_ARTICLES.map((article) => (
            <li key={article.slug} className="flex h-full flex-col rounded-xl border border-[#EDE7F6] bg-white shadow-sm">
              <div
                aria-hidden="true"
                className="h-44 rounded-t-xl bg-gradient-to-br from-[#6B2FA0]/15 to-[#C9A227]/20"
              />
              <div className="flex grow flex-col p-5">
                <p className="flex items-center gap-2 text-xs uppercase tracking-wide text-[#666]">
                  <Calendar size={12} aria-hidden="true" />
                  <span>{formatDateFr(article.dateIso)}</span>
                  <span aria-hidden="true">·</span>
                  <span className="font-semibold text-[#6B2FA0]">{t(article.categoryKey)}</span>
                </p>
                <h3 className="mt-3 grow font-heading text-lg font-bold leading-snug text-[#333]">
                  {t(article.titleKey)}
                </h3>
                <p className="mt-2 text-sm text-[#555]">{t(article.excerptKey)}</p>
                <Link
                  href={`/actualites/${article.slug}`}
                  className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-[#6B2FA0] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded"
                >
                  {t('readMore')}
                  <ArrowRight size={14} aria-hidden="true" />
                </Link>
              </div>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
