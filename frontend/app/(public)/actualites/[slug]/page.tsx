import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ArrowLeft, ArrowRight, Calendar, Pin } from 'lucide-react';
import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { Breadcrumbs } from '@/components/Breadcrumbs';
import { InternalPageCta } from '@/components/InternalPageCta';
import { InternalPageHero } from '@/components/InternalPageHero';
import { JsonLd, articleJsonLd } from '@/components/JsonLd';
import { PageRenderer } from '@/components/PageRenderer';
import { getArticleBySlug } from '@/lib/api/articles';
import { mediaUrl } from '@/lib/media';

export const revalidate = 300;

interface PageProps {
  params: Promise<{ slug: string }>;
}

function formatDateFr(iso: string | null): string {
  if (!iso) return '';
  return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' }).format(
    new Date(iso),
  );
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;
  const result = await getArticleBySlug(slug);
  if (!result.ok) {
    return { title: 'Article introuvable' };
  }
  return {
    title: result.data.title,
    description: result.data.excerpt ?? undefined,
  };
}

export default async function ActualiteDetailPage({ params }: PageProps): Promise<JSX.Element> {
  const { slug } = await params;
  const result = await getArticleBySlug(slug);

  if (!result.ok) {
    if (result.error.status === 404) {
      notFound();
    }
    return (
      <div className="mx-auto max-w-3xl px-6 py-12 md:py-16">
        <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">Article indisponible</h1>
        <p
          role="alert"
          className="mt-6 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-700"
        >
          Erreur de chargement : {result.error.message}.
        </p>
      </div>
    );
  }

  const article = result.data;
  const t = await getTranslations('actualites.detail');
  const imageSrc = article.featured_image_path
    ? mediaUrl(article.featured_image_path)
    : null;

  return (
    <>
      <JsonLd
        data={articleJsonLd({
          title: article.title,
          description: article.excerpt ?? article.title,
          slug: article.slug,
          datePublished: article.published_at,
        })}
      />

      <InternalPageHero
        eyebrow={article.category_label ?? t('eyebrow')}
        title={article.title}
        excerpt={article.excerpt}
        imageSrc={imageSrc}
        imageMode="panel"
        meta={(
          <p className="flex flex-wrap items-center gap-3 text-xs text-white/80" data-testid="article-meta">
            <span className="inline-flex items-center gap-1.5">
              <Calendar size={13} aria-hidden="true" />
              {formatDateFr(article.published_at)}
            </span>
            {article.is_pinned && (
              <span className="inline-flex items-center gap-1 rounded-full border border-white/25 px-2 py-1 text-white">
                <Pin size={11} aria-hidden="true" />
                {t('pinned')}
              </span>
            )}
          </p>
        )}
      />

      <div className="mx-auto max-w-6xl px-6 pt-6">
        <Breadcrumbs
          items={[
            { href: '/', label: 'Accueil' },
            { href: '/actualites', label: 'Actualités' },
            { label: article.title },
          ]}
        />
      </div>

      <div className="mx-auto grid max-w-6xl gap-10 px-6 pb-14 pt-8 lg:grid-cols-[minmax(0,1fr)_280px] lg:gap-14 md:pb-16">
        <div>
          {article.body && (
            <div className="prose prose-pssfp max-w-none text-justify hyphens-auto leading-relaxed [&_blockquote]:border-l-4 [&_blockquote]:border-[#D4AF6A] [&_blockquote]:bg-[#FFFBEA] [&_blockquote]:px-6 [&_blockquote]:py-4 [&_blockquote]:not-italic [&_blockquote]:text-[#444] [&_h2]:mt-10 [&_h2]:font-heading [&_h2]:text-2xl [&_h2]:text-[#4A2E67]">
              <PageRenderer body={article.body} className="!p-0" />
            </div>
          )}

          <div className="mt-12 border-t border-[var(--pssfp-border)] pt-6">
            <Link
              href="/actualites"
              className="inline-flex h-11 items-center gap-2 rounded-md border border-[var(--pssfp-border)] bg-[var(--pssfp-bg-elevated)] px-4 text-sm font-medium text-[var(--pssfp-text)] transition-colors hover:border-pssfp-prune hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 dark:hover:border-[#B084E8] dark:hover:text-[#B084E8]"
            >
              <ArrowLeft size={15} aria-hidden="true" />
              {t('back')}
            </Link>
          </div>
        </div>

        <aside className="lg:sticky lg:top-28 lg:self-start">
          <div className="border-l-2 border-pssfp-or bg-[var(--pssfp-bg-subtle)] p-5">
            <p className="pssfp-eyebrow">{t('summaryTitle')}</p>
            <p className="mt-3 text-sm leading-relaxed text-[var(--pssfp-text-muted)]">
              {t('summaryBody')}
            </p>
            <a
              href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
              className="group mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-pssfp-prune transition-colors hover:text-pssfp-prune-dark dark:text-[#B084E8] dark:hover:text-[#C9A0F0]"
            >
              {t('apply')}
              <ArrowRight size={14} aria-hidden="true" className="transition-transform group-hover:translate-x-0.5" />
            </a>
          </div>
        </aside>
      </div>

      <InternalPageCta />
    </>
  );
}
