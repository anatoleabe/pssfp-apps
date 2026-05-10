import Link from 'next/link';
import Image from 'next/image';
import { notFound } from 'next/navigation';
import { Calendar, Pin } from 'lucide-react';
import type { Metadata } from 'next';
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
        <h1 className="font-heading text-3xl font-bold text-[#6B2FA0]">Article indisponible</h1>
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

      {/* Hero éditorial avec photo article si dispo */}
      {article.featured_image_path ? (
        <header className="relative isolate overflow-hidden border-b border-[#EDE7F6] dark:border-[#3A2A55]">
          <div className="relative h-[40vh] min-h-[320px] w-full md:h-[55vh]">
            <Image
              src={mediaUrl(article.featured_image_path)}
              alt=""
              fill
              sizes="100vw"
              priority
              className="object-cover object-center"
            />
            <div aria-hidden="true" className="absolute inset-0 bg-gradient-to-t from-[#1A0A2E]/95 via-[#14091F]/40 to-transparent" />
            <div className="absolute inset-x-0 bottom-0 mx-auto max-w-4xl px-6 pb-8 md:pb-12">
              <p className="flex flex-wrap items-center gap-2 text-xs text-white/90" data-testid="article-meta">
                {article.category_label && (
                  <span className="inline-flex items-center rounded-full bg-[#C9A227] px-3 py-1 text-xs font-semibold uppercase tracking-wider text-[#1A0A2E]">
                    {article.category_label}
                  </span>
                )}
                <span className="inline-flex items-center gap-1">
                  <Calendar size={12} aria-hidden="true" />
                  {formatDateFr(article.published_at)}
                </span>
                {article.is_pinned && (
                  <span className="inline-flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5 backdrop-blur-md">
                    <Pin size={10} aria-hidden="true" />
                    À la une
                  </span>
                )}
              </p>
              <h1 className="mt-3 max-w-3xl font-heading text-3xl font-bold leading-tight text-white md:text-5xl">
                {article.title}
              </h1>
              {article.excerpt && (
                <p className="mt-4 max-w-3xl text-lg text-white/85">{article.excerpt}</p>
              )}
            </div>
          </div>
        </header>
      ) : (
        <header className="border-b border-[#EDE7F6] bg-gradient-lavande-blanc py-12 md:py-16 dark:border-[#3A2A55] dark:bg-[#1A0A2E] dark:bg-none">
          <div className="mx-auto max-w-4xl px-6">
            <p className="flex flex-wrap items-center gap-2 text-xs text-[#666] dark:text-[#B5A8C8]" data-testid="article-meta">
              <span className="inline-flex items-center gap-1">
                <Calendar size={12} aria-hidden="true" />
                {formatDateFr(article.published_at)}
              </span>
              {article.category_label && (
                <>
                  <span aria-hidden="true">·</span>
                  <span className="font-semibold text-[#6B2FA0] dark:text-[#B084E8]">{article.category_label}</span>
                </>
              )}
              {article.is_pinned && (
                <span className="inline-flex items-center gap-1 rounded-full bg-[#FFFBEA] px-2 py-0.5 text-[#A57A00]">
                  <Pin size={10} aria-hidden="true" />
                  À la une
                </span>
              )}
            </p>
            <h1 className="mt-3 font-heading text-3xl font-bold leading-tight text-[#6B2FA0] md:text-5xl dark:text-[#B084E8]">
              {article.title}
            </h1>
            {article.excerpt && (
              <p className="mt-3 text-lg text-[#555] dark:text-[#B5A8C8]">{article.excerpt}</p>
            )}
          </div>
        </header>
      )}

      <div className="mx-auto max-w-3xl px-6 pt-6">
        <nav aria-label="Fil d'Ariane" className="mb-6 text-sm text-[#666] dark:text-[#B5A8C8]">
          <Link href="/" className="hover:text-[#6B2FA0] dark:hover:text-[#B084E8]">Accueil</Link>
          <span aria-hidden="true"> / </span>
          <Link href="/actualites" className="hover:text-[#6B2FA0] dark:hover:text-[#B084E8]">Actualités</Link>
          <span aria-hidden="true"> / </span>
          <span className="text-[#333] dark:text-[#F5EFE3]">{article.title}</span>
        </nav>
      </div>

      <article className="mx-auto max-w-3xl px-6 pb-12 md:pb-16">
        {article.body && (
          <div className="prose prose-pssfp max-w-none text-justify hyphens-auto leading-relaxed [&_blockquote]:border-l-4 [&_blockquote]:border-[#C9A227] [&_blockquote]:bg-[#FFFBEA] [&_blockquote]:px-6 [&_blockquote]:py-4 [&_blockquote]:not-italic [&_blockquote]:text-[#444] [&_h2]:mt-10 [&_h2]:font-heading [&_h2]:text-2xl [&_h2]:text-[#6B2FA0]">
            <PageRenderer body={article.body} className="!p-0" />
          </div>
        )}

        <div className="mt-12 border-t border-[#EDE7F6] pt-6 dark:border-[#3A2A55]">
          <Link
            href="/actualites"
            className="inline-flex h-11 items-center rounded-md border border-[#EDE7F6] bg-white px-4 text-sm text-[#333] hover:border-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 dark:border-[#3A2A55] dark:bg-[#1F0E2E] dark:text-[#F5EFE3]"
          >
            ← Toutes les actualités
          </Link>
        </div>
      </article>
    </>
  );
}
