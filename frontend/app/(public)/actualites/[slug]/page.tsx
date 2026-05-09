import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Calendar, Pin } from 'lucide-react';
import type { Metadata } from 'next';
import { JsonLd, articleJsonLd } from '@/components/JsonLd';
import { PageRenderer } from '@/components/PageRenderer';
import { getArticleBySlug } from '@/lib/api/articles';

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
    <article className="mx-auto max-w-3xl px-6 py-12 md:py-16">
      <JsonLd
        data={articleJsonLd({
          title: article.title,
          description: article.excerpt ?? article.title,
          slug: article.slug,
          datePublished: article.published_at,
        })}
      />
      <nav aria-label="Fil d'Ariane" className="mb-6 text-sm text-[#666]">
        <Link href="/" className="hover:text-[#6B2FA0]">Accueil</Link>
        <span aria-hidden="true"> / </span>
        <Link href="/actualites" className="hover:text-[#6B2FA0]">Actualités</Link>
        <span aria-hidden="true"> / </span>
        <span className="text-[#333]">{article.title}</span>
      </nav>

      <header className="mb-8">
        <p className="flex flex-wrap items-center gap-2 text-xs text-[#666]" data-testid="article-meta">
          <span className="inline-flex items-center gap-1">
            <Calendar size={12} aria-hidden="true" />
            {formatDateFr(article.published_at)}
          </span>
          {article.category_label && (
            <>
              <span aria-hidden="true">·</span>
              <span className="font-semibold text-[#6B2FA0]">{article.category_label}</span>
            </>
          )}
          {article.is_pinned && (
            <span className="inline-flex items-center gap-1 rounded-full bg-[#FFFBEA] px-2 py-0.5 text-[#A57A00]">
              <Pin size={10} aria-hidden="true" />
              À la une
            </span>
          )}
        </p>
        <h1 className="mt-3 font-heading text-3xl font-bold leading-tight text-[#6B2FA0] md:text-4xl">
          {article.title}
        </h1>
        {article.excerpt && (
          <p className="mt-3 text-lg text-[#555]">{article.excerpt}</p>
        )}
      </header>

      {article.body && <PageRenderer body={article.body} />}

      <div className="mt-12 border-t border-[#EDE7F6] pt-6">
        <Link
          href="/actualites"
          className="inline-flex h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm text-[#333] hover:border-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
        >
          ← Toutes les actualités
        </Link>
      </div>
    </article>
  );
}
