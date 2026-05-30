import Link from 'next/link';
import { ArrowRight } from 'lucide-react';
import type { Metadata } from 'next';
import { PageRenderer } from '@/components/PageRenderer';
import { getPageBySlug } from '@/lib/api/pages';

export const revalidate = 300;

export const metadata: Metadata = {
  title: 'Formations',
  description: 'Master en finances publiques (5 spécialités), formation continue et certifications du PSSFP.',
};

const QUICK_LINKS = [
  { slug: 'formations/master', label: 'Master Professionnel' },
  { slug: 'formations/specialites', label: '5 spécialités du Master' },
  { slug: 'formations/formation-continue', label: 'Formation continue (10 modules)' },
  { slug: 'formations/certifications', label: 'Certifications internationales' },
  { slug: 'formations/seminaires', label: 'Séminaires & voyages d\'étude' },
  { slug: 'formations/admission', label: 'Admission (P14 — 2026)' },
  { slug: 'formations/frais-de-scolarite', label: 'Frais de scolarité' },
] as const;

export default async function FormationsIndexPage(): Promise<JSX.Element> {
  const result = await getPageBySlug('formations');

  return (
    <div className="mx-auto max-w-4xl px-6 py-12 md:py-16">
      <header className="mb-8">
        <h1 className="font-heading text-3xl font-bold text-[#4A2E67] md:text-4xl">
          Formations supérieures
        </h1>
        {result.ok && result.data.excerpt && (
          <p className="mt-3 text-lg text-[#555]">{result.data.excerpt}</p>
        )}
      </header>

      <ul className="grid gap-4 md:grid-cols-2">
        {QUICK_LINKS.map((link) => (
          <li key={link.slug}>
            <Link
              href={`/${link.slug}`}
              data-testid={`formations-link-${link.slug.split('/').pop()}`}
              className="group flex h-full items-center justify-between gap-4 rounded-lg border border-[#F4EFFA] bg-white p-5 transition-all hover:-translate-y-0.5 hover:border-[#5C3A7E] hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
            >
              <span className="font-heading text-lg font-semibold text-[#333] group-hover:text-[#4A2E67]">
                {link.label}
              </span>
              <ArrowRight size={18} aria-hidden="true" className="text-[#4A2E67] transition-transform group-hover:translate-x-1" />
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
