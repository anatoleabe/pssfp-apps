import Link from 'next/link';
import { ArrowRight, Calendar, Pin } from 'lucide-react';
import type { Metadata } from 'next';
import { FacebookEmbed } from '@/components/FacebookEmbed';
import { listArticles } from '@/lib/api/articles';

export const revalidate = 300;

export const metadata: Metadata = {
  title: 'Actualités',
  description: 'Toutes les actualités du PSSFP — événements, coopération, vie académique, communiqués.',
};

interface ActualitesPageProps {
  searchParams: Promise<{ page?: string }>;
}

function formatDateFr(iso: string | null): string {
  if (!iso) return '';
  return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' }).format(
    new Date(iso),
  );
}

export default async function ActualitesIndexPage({
  searchParams,
}: ActualitesPageProps): Promise<JSX.Element> {
  const { page } = await searchParams;
  const pageNum = page ? Math.max(1, Number.parseInt(page, 10) || 1) : 1;
  const result = await listArticles({ page: pageNum });

  return (
    <div className="mx-auto max-w-6xl px-6 py-12 md:py-16">
      <header className="mb-10 flex flex-wrap items-baseline justify-between gap-4">
        <div>
          <h1 className="font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl">Actualités</h1>
          <p className="mt-3 max-w-2xl text-[#555]">
            Événements, coopération, vie académique et communiqués officiels du PSSFP.
          </p>
        </div>
      </header>

      {!result.ok ? (
        <p
          role="alert"
          data-testid="actualites-error"
          className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
        >
          Le flux d'actualités est temporairement indisponible. Réessayez dans quelques instants.
        </p>
      ) : result.data.data.length === 0 ? (
        <p
          role="status"
          data-testid="actualites-empty"
          className="rounded-md border border-[#EDE7F6] bg-[#FAF7FF] p-4 text-sm text-[#555]"
        >
          Aucune actualité publiée pour le moment.
        </p>
      ) : (
        <div className="grid gap-10 lg:grid-cols-[2fr_1fr]">
          <ul className="grid gap-6 md:grid-cols-2">
            {result.data.data.map((article) => (
              <li
                key={article.slug}
                data-testid={`actualite-card-${article.slug}`}
                className="flex h-full flex-col rounded-xl border border-[#EDE7F6] bg-white shadow-sm"
              >
                <div
                  aria-hidden="true"
                  className="h-40 rounded-t-xl bg-gradient-to-br from-[#6B2FA0]/15 to-[#C9A227]/20"
                />
                <div className="flex grow flex-col p-5">
                  <p className="flex flex-wrap items-center gap-2 text-xs text-[#666]">
                    <span className="inline-flex items-center gap-1">
                      <Calendar size={12} aria-hidden="true" />
                      {formatDateFr(article.published_at)}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span className="font-semibold text-[#6B2FA0]">{article.category_label}</span>
                    {article.is_pinned && (
                      <span className="inline-flex items-center gap-1 rounded-full bg-[#FFFBEA] px-2 py-0.5 text-[#A57A00]">
                        <Pin size={10} aria-hidden="true" />
                        À la une
                      </span>
                    )}
                  </p>
                  <h2 className="mt-3 grow font-heading text-lg font-bold leading-snug text-[#333]">
                    <Link
                      href={`/actualites/${article.slug}`}
                      className="hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded"
                    >
                      {article.title}
                    </Link>
                  </h2>
                  {article.excerpt && (
                    <p className="mt-2 text-sm text-[#555]">{article.excerpt}</p>
                  )}
                  <Link
                    href={`/actualites/${article.slug}`}
                    className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-[#6B2FA0] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded"
                  >
                    Lire la suite
                    <ArrowRight size={14} aria-hidden="true" />
                  </Link>
                </div>
              </li>
            ))}
          </ul>

          <aside aria-label="Flux Facebook officiel">
            <h2 className="mb-4 font-heading text-lg font-bold text-[#6B2FA0]">
              Sur Facebook
            </h2>
            <FacebookEmbed />
          </aside>
        </div>
      )}

      {result.ok && result.data.meta.last_page > 1 && (
        <nav
          aria-label="Pagination des actualités"
          className="mt-10 flex items-center justify-center gap-2 text-sm"
          data-testid="actualites-pagination"
        >
          {pageNum > 1 && (
            <Link
              href={`/actualites?page=${pageNum - 1}`}
              className="inline-flex h-10 items-center rounded-md border border-gray-300 bg-white px-4 hover:border-[#6B2FA0]"
            >
              ← Précédent
            </Link>
          )}
          <span className="px-3 text-[#555]">
            Page {pageNum} sur {result.data.meta.last_page}
          </span>
          {pageNum < result.data.meta.last_page && (
            <Link
              href={`/actualites?page=${pageNum + 1}`}
              className="inline-flex h-10 items-center rounded-md border border-gray-300 bg-white px-4 hover:border-[#6B2FA0]"
            >
              Suivant →
            </Link>
          )}
        </nav>
      )}
    </div>
  );
}
