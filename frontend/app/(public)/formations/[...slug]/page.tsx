import Link from 'next/link';
import { notFound } from 'next/navigation';
import type { Metadata } from 'next';
import { JsonLd, programJsonLd } from '@/components/JsonLd';
import { PageRenderer } from '@/components/PageRenderer';
import { getPageBySlug } from '@/lib/api/pages';

export const revalidate = 300;

interface PageProps {
  params: Promise<{ slug: string[] }>;
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;
  const fullSlug = `formations/${slug.join('/')}`;
  const result = await getPageBySlug(fullSlug);
  if (!result.ok) {
    return { title: 'Page introuvable' };
  }
  return {
    title: result.data.meta_title ?? result.data.title,
    description: result.data.meta_description ?? result.data.excerpt ?? undefined,
  };
}

function eyebrowFor(slug: string): string {
  if (slug === 'formations/master') return 'Master Professionnel';
  if (slug === 'formations/formation-continue') return 'Formation continue';
  if (slug === 'formations/certifications') return 'Certifications internationales';
  if (slug === 'formations/seminaires') return 'Séminaires & voyages d\'étude';
  if (slug === 'formations/admission') return 'Admission';
  if (slug === 'formations/frais-de-scolarite') return 'Frais de scolarité';
  if (slug.startsWith('formations/specialites/')) return 'Master — Spécialité';
  if (slug.startsWith('formations/formation-continue/')) return 'Formation continue — Module';
  return 'Formations';
}

export default async function FormationsCatchallPage({ params }: PageProps): Promise<JSX.Element> {
  const { slug } = await params;
  const fullSlug = `formations/${slug.join('/')}`;
  const result = await getPageBySlug(fullSlug);

  if (!result.ok) {
    if (result.error.status === 404) {
      notFound();
    }
    return (
      <div className="mx-auto max-w-3xl px-6 py-12 md:py-16">
        <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">Page indisponible</h1>
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
  const isSpecialty =
    page.slug.startsWith('formations/specialites/') && page.slug !== 'formations/specialites';

  return (
    <>
      {isSpecialty && (
        <JsonLd
          data={programJsonLd({
            name: page.title,
            description: page.excerpt ?? page.title,
            slug: page.slug,
          })}
        />
      )}

      {/* Hero éditorial */}
      <header className="relative overflow-hidden border-b border-[#F4EFFA] bg-gradient-lavande-blanc py-12 md:py-16 dark:border-[#3A2A55] dark:bg-[#1A1A1A] dark:bg-none">
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-0 opacity-50 dark:opacity-30">
          <div className="absolute -top-24 -right-24 h-96 w-96 rounded-full bg-[#5C3A7E]/15 blur-3xl dark:bg-[#B084E8]/10" />
          <div className="absolute -bottom-32 -left-16 h-[28rem] w-[28rem] rounded-full bg-[#D4AF6A]/10 blur-3xl dark:bg-[#E5C788]/10" />
        </div>
        <div className="relative mx-auto max-w-5xl px-6">
          <p className="pssfp-eyebrow text-[#D4AF6A]">{eyebrowFor(page.slug)}</p>
          <h1 className="mt-3 font-heading text-3xl font-bold leading-tight text-[#4A2E67] md:text-5xl dark:text-[#B084E8]">
            {page.title}
          </h1>
          {page.excerpt && (
            <p className="mt-4 max-w-3xl text-lg text-[#555] dark:text-[#B5A8C8]" data-testid="page-excerpt">
              {page.excerpt}
            </p>
          )}
        </div>
      </header>

      <div className="mx-auto max-w-4xl px-6 pt-6">
        <nav aria-label="Fil d'Ariane" className="mb-6 text-sm text-[#666] dark:text-[#B5A8C8]">
          <Link href="/" className="hover:text-[#4A2E67] dark:hover:text-[#B084E8]">Accueil</Link>
          <span aria-hidden="true"> / </span>
          <Link href="/formations" className="hover:text-[#4A2E67] dark:hover:text-[#B084E8]">Formations</Link>
          <span aria-hidden="true"> / </span>
          <span className="text-[#333] dark:text-[#F5EFE3]" data-testid="breadcrumb-current">
            {page.menu_label ?? page.title}
          </span>
        </nav>
      </div>

      <div className="mx-auto max-w-4xl px-6 pb-12 md:pb-16">
        {page.body && (
          <div className="prose prose-pssfp max-w-none text-justify hyphens-auto leading-relaxed [&_blockquote]:border-l-4 [&_blockquote]:border-[#D4AF6A] [&_blockquote]:bg-[#FFFBEA] [&_blockquote]:px-6 [&_blockquote]:py-4 [&_blockquote]:not-italic [&_blockquote]:text-[#444] [&_h2]:mt-10 [&_h2]:font-heading [&_h2]:text-2xl [&_h2]:text-[#4A2E67] [&_h3]:mt-6 [&_h3]:font-heading [&_h3]:text-xl [&_table]:overflow-hidden [&_table]:rounded-pssfp-card [&_table]:border [&_table]:border-[#F4EFFA] [&_th]:bg-[#F4EFFA] [&_th]:p-3 [&_td]:p-3 [&_td]:border-t [&_td]:border-[#F4EFFA]">
            <PageRenderer body={page.body} className="!p-0" />
          </div>
        )}
      </div>
    </>
  );
}
