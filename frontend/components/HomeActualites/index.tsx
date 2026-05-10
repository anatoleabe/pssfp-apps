import Link from 'next/link';
import Image from 'next/image';
import { ArrowRight, Calendar } from 'lucide-react';
import { getTranslations } from 'next-intl/server';
import { listArticles, type ApiArticle } from '@/lib/api/articles';
import { mediaUrl } from '@/lib/media';

/**
 * Bloc « 3 actualités à la une » — refonte PR R + Sprint S5 PR Z.
 *
 * Cards avec image header (photo MinIO ou gradient fallback) + animation
 * reveal au hover, badge catégorie en pill, footer avec read-more.
 *
 * Sprint S5 PR Z : remplace les MOCK_ARTICLES par un fetch réel sur
 * `/v1/articles?featured=true`. Si l'API est down ou retourne 0 articles,
 * fallback sur le bloc placeholder.
 */

const ACCENT_BY_CATEGORY: Record<string, 'violet' | 'or' | 'forest'> = {
  evenement: 'violet',
  cooperation: 'or',
  'vie-academique': 'forest',
  communique: 'violet',
  partenariat: 'or',
};

function formatDateFr(iso: string): string {
  return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' }).format(
    new Date(iso),
  );
}

export async function HomeActualites(): Promise<JSX.Element> {
  const t = await getTranslations('home.actualites');

  const accentBg = {
    violet: 'bg-gradient-violet',
    or: 'bg-gradient-or-soft',
    forest: 'bg-gradient-forest',
  } as const;

  const articlesResult = await listArticles({ featured: true });
  const realArticles: ApiArticle[] = articlesResult.ok
    ? articlesResult.data.data.slice(0, 3)
    : [];
  const hasRealArticles = realArticles.length > 0;

  return (
    <section
      aria-labelledby="actualites-heading"
      data-testid="home-actualites"
      className="bg-[#FBF7EE]"
    >
      <div className="mx-auto max-w-7xl px-6 py-20 md:py-24">
        <header className="mb-12 flex flex-wrap items-end justify-between gap-4">
          <div className="max-w-2xl">
            <p className="pssfp-eyebrow">{t('eyebrow')}</p>
            <h2
              id="actualites-heading"
              className="mt-3 font-heading font-bold text-pssfp-h2 text-[#14101A]"
            >
              {t('title')}
            </h2>
          </div>
          <Link
            href="/actualites"
            className="group inline-flex items-center gap-2 rounded-pssfp-button border border-[#E4D8B7] bg-white px-4 py-2.5 text-sm font-semibold text-[#0E4D3F] transition-all duration-200 hover:border-[#0E4D3F] hover:shadow-pssfp-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0E4D3F] focus-visible:ring-offset-2"
          >
            {t('seeAll')}
            <ArrowRight
              size={14}
              aria-hidden="true"
              className="transition-transform duration-200 group-hover:translate-x-0.5"
            />
          </Link>
        </header>

        {/* Bandeau placeholder visible uniquement si l'API retourne 0 article featured */}
        {!hasRealArticles && (
          <p
            role="note"
            data-testid="home-actualites-placeholder"
            className="mb-8 rounded-pssfp-card border border-amber-300 bg-amber-50 p-4 text-xs leading-relaxed text-amber-900"
          >
            {t('placeholder')}
          </p>
        )}

        <ul className="grid gap-6 md:grid-cols-3">
          {hasRealArticles
            ? realArticles.map((article) => {
                const accent = ACCENT_BY_CATEGORY[article.category ?? ''] ?? 'violet';
                const dateIso = article.published_at ?? new Date().toISOString();
                return (
                  <li key={article.uuid}>
                    <article
                      data-testid={`home-actualites-card-${article.slug}`}
                      className="group flex h-full flex-col overflow-hidden rounded-pssfp-card border border-[#E4D8B7] bg-white shadow-pssfp-soft transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 hover:border-[#C9A227]/60 hover:shadow-pssfp-elevated"
                    >
                      {/* Header image MinIO ou gradient fallback */}
                      <div
                        aria-hidden="true"
                        className={`relative h-48 overflow-hidden ${accentBg[accent]}`}
                      >
                        {article.featured_image_path && (
                          <Image
                            src={mediaUrl(article.featured_image_path)}
                            alt=""
                            fill
                            sizes="(max-width: 768px) 100vw, 33vw"
                            className="object-cover transition-transform duration-700 ease-pssfp-out-expo group-hover:scale-105"
                          />
                        )}
                        <div className="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent" />
                      </div>

                      <div className="flex grow flex-col p-6">
                        <p className="flex flex-wrap items-center gap-2 text-xs">
                          <span className="inline-flex items-center gap-1 text-[#6B6378]">
                            <Calendar size={12} aria-hidden="true" />
                            <time dateTime={dateIso}>{formatDateFr(dateIso)}</time>
                          </span>
                          <span className="inline-flex items-center rounded-full bg-[#EFE6CE] px-2.5 py-1 text-xs font-semibold uppercase tracking-wider text-[#14101A]">
                            {article.category_label ?? article.category ?? ''}
                          </span>
                        </p>
                        <h3 className="mt-4 grow font-heading text-pssfp-h3 font-bold leading-snug text-[#14101A]">
                          {article.title}
                        </h3>
                        {article.excerpt && (
                          <p className="mt-3 text-sm leading-relaxed text-[#6B6378]">
                            {article.excerpt}
                          </p>
                        )}
                        <Link
                          href={`/actualites/${article.slug}`}
                          data-testid={`home-actualites-link-${article.slug}`}
                          className="mt-5 inline-flex items-center gap-1.5 self-start rounded text-sm font-semibold text-[#0E4D3F] transition-all duration-200 hover:gap-2.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0E4D3F] focus-visible:ring-offset-2"
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
                );
              })
            : null}
        </ul>
      </div>
    </section>
  );
}
