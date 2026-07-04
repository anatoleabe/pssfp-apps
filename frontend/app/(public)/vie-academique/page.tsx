import Link from 'next/link';
import { ArrowRight, Users, GraduationCap, Calendar, Briefcase, Globe2, Plane } from 'lucide-react';
import type { Metadata } from 'next';
import { InternalPageCta } from '@/components/InternalPageCta';
import { InternalPageHero } from '@/components/InternalPageHero';
import { PageRenderer } from '@/components/PageRenderer';
import { getPageBySlug } from '@/lib/api/pages';

export const revalidate = 300;

export const metadata: Metadata = {
  title: 'Vie académique',
  description: 'Promotions, enseignants, calendrier académique, stages, programme MEDIAFIP et coopération internationale au PSSFP.',
};

const QUICK_LINKS = [
  { slug: 'vie-academique/promotions', label: '13 promotions', Icon: GraduationCap },
  { slug: 'vie-academique/corps-enseignant', label: 'Corps enseignant', Icon: Users },
  { slug: 'vie-academique/calendrier-academique', label: 'Calendrier 2026-2027', Icon: Calendar },
  { slug: 'vie-academique/stages-et-soutenances', label: 'Stages et soutenances', Icon: Briefcase },
  { slug: 'vie-academique/programme-mediafip', label: 'Programme MEDIAFIP', Icon: Plane },
  { slug: 'vie-academique/cooperation-internationale', label: 'Coopération internationale', Icon: Globe2 },
] as const;

export default async function VieAcademiqueIndexPage(): Promise<JSX.Element> {
  const result = await getPageBySlug('vie-academique');

  return (
    <>
      <InternalPageHero
        eyebrow="Communauté PSSFP"
        title="Vie académique"
        excerpt={result.ok ? result.data.excerpt : undefined}
      />

      <div className="mx-auto max-w-5xl px-6 py-12 md:py-16">
      <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {QUICK_LINKS.map(({ slug, label, Icon }) => (
          <li key={slug}>
            <Link
              href={`/${slug}`}
              data-testid={`vie-link-${slug.split('/').pop()}`}
              className="group flex h-full flex-col items-start gap-3 rounded-pssfp-card border border-[var(--pssfp-border)] bg-[var(--pssfp-bg-elevated)] p-5 shadow-pssfp-soft transition-all hover:-translate-y-0.5 hover:border-pssfp-prune hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 dark:hover:border-[#B084E8]"
            >
              <span className="inline-flex h-10 w-10 items-center justify-center rounded-md bg-[var(--pssfp-primary-soft)] text-pssfp-prune dark:text-[#B084E8]">
                <Icon size={20} aria-hidden="true" />
              </span>
              <span className="grow font-heading text-lg font-semibold text-[var(--pssfp-text-strong)] group-hover:text-pssfp-prune dark:group-hover:text-[#B084E8]">
                {label}
              </span>
              <ArrowRight size={16} aria-hidden="true" className="text-pssfp-or transition-transform group-hover:translate-x-1" />
            </Link>
          </li>
        ))}
      </ul>

      {result.ok && result.data.body && (
        <div className="mt-12">
          <PageRenderer body={result.data.body} />
        </div>
      )}
      </div>

      <InternalPageCta />
    </>
  );
}
