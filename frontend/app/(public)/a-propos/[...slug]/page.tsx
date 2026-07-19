import Image from 'next/image';
import { notFound } from 'next/navigation';
import type { Metadata } from 'next';
import { Breadcrumbs } from '@/components/Breadcrumbs';
import { CamesGrid } from '@/components/CamesGrid';
import { InternalPageCta } from '@/components/InternalPageCta';
import { InternalPageHero } from '@/components/InternalPageHero';
import { OrganigrammeChart } from '@/components/OrganigrammeChart';
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

function Breadcrumb({ pageLabel }: { pageLabel: string }): JSX.Element {
  return (
    <Breadcrumbs
      items={[
        { href: '/', label: 'Accueil' },
        { href: '/a-propos', label: 'À propos de nous' },
        { label: pageLabel },
      ]}
    />
  );
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
  const isCames = page.slug === 'a-propos/conformite-cames';
  const isMotPresident = page.slug === 'a-propos/mot-president';
  const isOrganigramme = page.slug === 'a-propos/organigramme';

  // Layout spécial Mot du Président : photo + texte justifié, allure éditoriale.
  if (isMotPresident) {
    return (
      <>
        <div className="mx-auto max-w-5xl px-6 pt-6">
          <Breadcrumb pageLabel={page.menu_label || page.title} />
        </div>
        <div className="mx-auto max-w-5xl px-6 pb-12 md:pb-16">
          <header className="mb-10">
            <p className="pssfp-eyebrow text-[#D4AF6A]">Le Comité de Pilotage</p>
            <h1 className="mt-3 font-heading text-3xl font-bold text-[#4A2E67] md:text-5xl dark:text-[#B084E8]">
              {page.title}
            </h1>
          </header>

          <div className="grid gap-10 md:grid-cols-[260px_1fr] md:gap-12 lg:grid-cols-[320px_1fr]">
            <aside className="md:sticky md:top-24 md:self-start">
              <figure className="overflow-hidden rounded-pssfp-card border border-[#F4EFFA] bg-white shadow-pssfp-elevated dark:border-[#3A2A55] dark:bg-[#1F0E2E]">
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
                <figcaption className="border-t border-[#F4EFFA] bg-[#FAF7F2] px-5 py-4 text-center dark:border-[#3A2A55] dark:bg-[#2A1640]">
                  <p className="font-heading text-base font-bold text-[#4A2E67] dark:text-[#B084E8]">
                    Dr. BASAHAG Achile Nestor
                  </p>
                  <p className="mt-1 text-xs text-[#555] dark:text-[#B5A8C8]">
                    Président du Comité de Pilotage
                  </p>
                </figcaption>
              </figure>
            </aside>

            <div className="prose prose-pssfp max-w-none text-justify hyphens-auto leading-relaxed [&_blockquote]:border-l-4 [&_blockquote]:border-[#D4AF6A] [&_blockquote]:bg-[#FFFBEA] [&_blockquote]:px-6 [&_blockquote]:py-4 [&_blockquote]:not-italic [&_blockquote]:text-[#444] [&_hr]:my-8 [&_hr]:border-[#D4AF6A]/40 [&_p]:mb-5">
              {page.body && <PageRenderer body={page.body} className="!p-0" />}
            </div>
          </div>
        </div>
        <InternalPageCta />
      </>
    );
  }

  // Layout spécial Organigramme : graphe hiérarchique
  if (isOrganigramme) {
    return (
      <>
        <InternalPageHero eyebrow="Structure organisationnelle" title={page.title} excerpt={page.excerpt} />
        <div className="mx-auto max-w-7xl px-6 pt-6">
          <Breadcrumb pageLabel={page.menu_label || page.title} />
        </div>
        <div className="mx-auto max-w-7xl px-6 pb-12 md:pb-16">
          <OrganigrammeChart />
          {page.body && (
            <details className="mt-12 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 dark:border-[#3A2A55] dark:bg-[#1F0E2E]">
              <summary className="cursor-pointer font-heading font-semibold text-[#4A2E67] dark:text-[#B084E8]">
                Voir la liste textuelle complète
              </summary>
              <div className="mt-4">
                <PageRenderer body={page.body} />
              </div>
            </details>
          )}
        </div>
        <InternalPageCta />
      </>
    );
  }

  // Layout par défaut : hero + prose
  return (
    <>
      <InternalPageHero
        eyebrow={isCames ? 'Accréditation' : 'À propos de nous'}
        title={page.title}
        excerpt={page.excerpt}
      />
      <div className="mx-auto max-w-4xl px-6 pt-6">
        <Breadcrumb pageLabel={page.menu_label || page.title} />
      </div>
      <div className="mx-auto max-w-4xl px-6 pb-12 md:pb-16">
        {page.body && (
          <div className="prose prose-pssfp max-w-none text-justify hyphens-auto leading-relaxed [&_blockquote]:border-l-4 [&_blockquote]:border-[#D4AF6A] [&_blockquote]:bg-[#FFFBEA] [&_blockquote]:px-6 [&_blockquote]:py-4 [&_blockquote]:not-italic [&_blockquote]:text-[#444] [&_h2]:mt-10 [&_h2]:font-heading [&_h2]:text-2xl [&_h2]:text-[#4A2E67] [&_h3]:mt-6 [&_h3]:font-heading [&_h3]:text-xl [&_table]:overflow-hidden [&_table]:rounded-pssfp-card [&_table]:border [&_table]:border-[#F4EFFA] [&_th]:bg-[#F4EFFA] [&_th]:p-3 [&_td]:p-3 [&_td]:border-t [&_td]:border-[#F4EFFA]">
            <PageRenderer body={page.body} className="!p-0" />
          </div>
        )}

        {isCames && (
          <div className="mt-12">
            <CamesGrid />
          </div>
        )}
      </div>
      <InternalPageCta />
    </>
  );
}
