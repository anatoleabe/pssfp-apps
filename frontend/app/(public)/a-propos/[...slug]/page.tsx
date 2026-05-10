import Link from 'next/link';
import Image from 'next/image';
import { notFound } from 'next/navigation';
import type { Metadata } from 'next';
import { CamesGrid } from '@/components/CamesGrid';
import { PageRenderer } from '@/components/PageRenderer';
import { getPageBySlug } from '@/lib/api/pages';
import { mediaUrl } from '@/lib/media';

export const revalidate = 300; // ISR 5 min sur les pages CMS.

interface PageProps {
  params: Promise<{ slug: string[] }>;
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;
  const fullSlug = `a-propos/${slug.join('/')}`;
  const result = await getPageBySlug(fullSlug);
  if (!result.ok) {
    return { title: 'Page introuvable' };
  }
  return {
    title: result.data.meta_title ?? result.data.title,
    description: result.data.meta_description ?? result.data.excerpt ?? undefined,
  };
}

export default async function AProposPage({ params }: PageProps): Promise<JSX.Element> {
  const { slug } = await params;
  const fullSlug = `a-propos/${slug.join('/')}`;
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
  const isCames = page.slug === 'a-propos/conformite-cames';
  const isMotPresident = page.slug === 'a-propos/mot-president';

  // Layout spécial Mot du Président : photo + texte justifié, allure éditoriale.
  if (isMotPresident) {
    return (
      <div className="mx-auto max-w-5xl px-6 py-12 md:py-16">
        <nav aria-label="Fil d'Ariane" className="mb-6 text-sm text-[#666] dark:text-[#B5A8C8]">
          <Link href="/" className="hover:text-[#6B2FA0] dark:hover:text-[#B084E8]">Accueil</Link>
          <span aria-hidden="true"> / </span>
          <Link href="/a-propos" className="hover:text-[#6B2FA0] dark:hover:text-[#B084E8]">À propos de nous</Link>
          <span aria-hidden="true"> / </span>
          <span className="text-[#333] dark:text-[#F5EFE3]" data-testid="breadcrumb-current">
            {page.menu_label ?? page.title}
          </span>
        </nav>

        <header className="mb-10">
          <p className="pssfp-eyebrow text-[#C9A227]">Le Comité de Pilotage</p>
          <h1 className="mt-3 font-heading text-3xl font-bold text-[#6B2FA0] md:text-5xl dark:text-[#B084E8]">
            {page.title}
          </h1>
        </header>

        <div className="grid gap-10 md:grid-cols-[260px_1fr] md:gap-12 lg:grid-cols-[320px_1fr]">
          <aside className="md:sticky md:top-24 md:self-start">
            <figure className="overflow-hidden rounded-pssfp-card border border-[#EDE7F6] bg-white shadow-pssfp-elevated dark:border-[#3A2A55] dark:bg-[#1F0E2E]">
              <div className="relative aspect-[4/5] w-full">
                <Image
                  src={mediaUrl('photos/direction/dr-basahag-achile.webp')}
                  alt="Portrait officiel du Dr. BASAHAG Achile Nestor"
                  fill
                  sizes="(max-width: 768px) 100vw, 320px"
                  className="object-cover object-center"
                  priority
                />
              </div>
              <figcaption className="border-t border-[#EDE7F6] bg-gradient-lavande-blanc px-5 py-4 text-center dark:border-[#3A2A55] dark:bg-[#2A1640]">
                <p className="font-heading text-base font-bold text-[#6B2FA0] dark:text-[#B084E8]">
                  Dr. BASAHAG Achile Nestor
                </p>
                <p className="mt-1 text-xs text-[#555] dark:text-[#B5A8C8]">
                  Président du Comité de Pilotage
                </p>
              </figcaption>
            </figure>
          </aside>

          <div className="prose prose-pssfp max-w-none text-justify hyphens-auto leading-relaxed [&_blockquote]:border-l-4 [&_blockquote]:border-[#C9A227] [&_blockquote]:bg-[#FFFBEA] [&_blockquote]:px-6 [&_blockquote]:py-4 [&_blockquote]:not-italic [&_blockquote]:text-[#444] [&_hr]:my-8 [&_hr]:border-[#C9A227]/40 [&_p]:mb-5">
            {page.body && <PageRenderer body={page.body} className="!p-0" />}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-3xl px-6 py-12 md:py-16">
      <nav aria-label="Fil d'Ariane" className="mb-6 text-sm text-[#666] dark:text-[#B5A8C8]">
        <Link href="/" className="hover:text-[#6B2FA0] dark:hover:text-[#B084E8]">Accueil</Link>
        <span aria-hidden="true"> / </span>
        <Link href="/a-propos" className="hover:text-[#6B2FA0] dark:hover:text-[#B084E8]">À propos de nous</Link>
        <span aria-hidden="true"> / </span>
        <span className="text-[#333] dark:text-[#F5EFE3]" data-testid="breadcrumb-current">
          {page.menu_label ?? page.title}
        </span>
      </nav>

      <header className="mb-8">
        <h1 className="font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl dark:text-[#B084E8]">
          {page.title}
        </h1>
        {page.excerpt && (
          <p className="mt-3 text-lg text-[#555] dark:text-[#B5A8C8]" data-testid="page-excerpt">
            {page.excerpt}
          </p>
        )}
      </header>

      {page.body && <PageRenderer body={page.body} />}

      {isCames && (
        <div className="mt-12">
          <CamesGrid />
        </div>
      )}
    </div>
  );
}
