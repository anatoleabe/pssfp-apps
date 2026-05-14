import Link from 'next/link';
import { ArrowRight, Users, GraduationCap, Calendar, Briefcase, Globe2, Plane } from 'lucide-react';
import type { Metadata } from 'next';
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
    <div className="mx-auto max-w-4xl px-6 py-12 md:py-16">
      <header className="mb-8">
        <h1 className="font-heading text-3xl font-bold text-[#4A2E67] md:text-4xl">
          Vie académique
        </h1>
        {result.ok && result.data.excerpt && (
          <p className="mt-3 text-lg text-[#555]">{result.data.excerpt}</p>
        )}
      </header>

      <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {QUICK_LINKS.map(({ slug, label, Icon }) => (
          <li key={slug}>
            <Link
              href={`/${slug}`}
              data-testid={`vie-link-${slug.split('/').pop()}`}
              className="group flex h-full flex-col items-start gap-3 rounded-lg border border-[#F4EFFA] bg-white p-5 transition-all hover:-translate-y-0.5 hover:border-[#5C3A7E] hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
            >
              <span className="inline-flex h-10 w-10 items-center justify-center rounded-md bg-[#F4EFFA] text-[#4A2E67]">
                <Icon size={20} aria-hidden="true" />
              </span>
              <span className="grow font-heading text-lg font-semibold text-[#333] group-hover:text-[#4A2E67]">
                {label}
              </span>
              <ArrowRight size={16} aria-hidden="true" className="text-[#4A2E67] transition-transform group-hover:translate-x-1" />
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
  );
}
