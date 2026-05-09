import Link from 'next/link';
import { notFound } from 'next/navigation';
import type { Metadata } from 'next';
import { PageRenderer } from '@/components/PageRenderer';
import { getPageBySlug } from '@/lib/api/pages';

export const revalidate = 300;

interface PageProps {
  params: Promise<{ slug: string[] }>;
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;
  const fullSlug = `vie-academique/${slug.join('/')}`;
  const result = await getPageBySlug(fullSlug);
  if (!result.ok) {
    return { title: 'Page introuvable' };
  }
  return {
    title: result.data.meta_title ?? result.data.title,
    description: result.data.meta_description ?? result.data.excerpt ?? undefined,
  };
}

export default async function VieAcademiqueCatchallPage({ params }: PageProps): Promise<JSX.Element> {
  const { slug } = await params;
  const fullSlug = `vie-academique/${slug.join('/')}`;
  const result = await getPageBySlug(fullSlug);

  if (!result.ok) {
    if (result.error.status === 404) {
      notFound();
    }
    return (
      <div className="mx-auto max-w-3xl px-6 py-12 md:py-16">
        <h1 className="font-heading text-3xl font-bold text-[#6B2FA0]">Page indisponible</h1>
        <p
          role="alert"
          className="mt-6 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-700"
          data-testid="page-error"
        >
          Erreur de chargement : {result.error.message}. Réessayez dans quelques instants.
        </p>
      </div>
    );
  }

  const page = result.data;

  return (
    <div className="mx-auto max-w-3xl px-6 py-12 md:py-16">
      <nav aria-label="Fil d'Ariane" className="mb-6 text-sm text-[#666]">
        <Link href="/" className="hover:text-[#6B2FA0]">Accueil</Link>
        <span aria-hidden="true"> / </span>
        <Link href="/vie-academique" className="hover:text-[#6B2FA0]">Vie académique</Link>
        <span aria-hidden="true"> / </span>
        <span className="text-[#333]" data-testid="breadcrumb-current">
          {page.menu_label ?? page.title}
        </span>
      </nav>

      <header className="mb-8">
        <h1 className="font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl">
          {page.title}
        </h1>
        {page.excerpt && (
          <p className="mt-3 text-lg text-[#555]">{page.excerpt}</p>
        )}
      </header>

      {page.body && <PageRenderer body={page.body} />}
    </div>
  );
}
