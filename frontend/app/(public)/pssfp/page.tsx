import Link from 'next/link';
import { ArrowRight } from 'lucide-react';
import type { Metadata } from 'next';
import { getMenu } from '@/lib/api/pages';

export const revalidate = 300;

export const metadata: Metadata = {
  title: 'Le PSSFP',
  description: 'Découvrez le Programme Supérieur de Spécialisation en Finances Publiques — présentation, gouvernance, partenaires et conformité CAMES.',
};

/**
 * Page parente /pssfp — sommaire des 8 sous-pages institutionnelles.
 * Fetch le menu pour récupérer les enfants `parent_slug = pssfp`.
 */
export default async function PssfpIndexPage(): Promise<JSX.Element> {
  const result = await getMenu();
  const children = result.ok
    ? (result.data.find((node) => node.slug === 'pssfp')?.children ?? [])
    : [];

  return (
    <div className="mx-auto max-w-4xl px-6 py-12 md:py-16">
      <header className="mb-10">
        <h1 className="font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl">Le PSSFP</h1>
        <p className="mt-3 text-lg text-[#555]">
          Programme Supérieur de Spécialisation en Finances Publiques — Campus de Messa, Yaoundé.
        </p>
      </header>

      {children.length === 0 ? (
        <p
          role="alert"
          className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
          data-testid="pssfp-empty"
        >
          Le contenu institutionnel sera publié dès l'activation du CMS Filament en production.
        </p>
      ) : (
        <ul className="grid gap-4 md:grid-cols-2">
          {children.map((child) => (
            <li key={child.slug}>
              <Link
                href={`/${child.slug}`}
                data-testid={`pssfp-card-${child.slug.split('/').pop()}`}
                className="group flex h-full items-center justify-between gap-4 rounded-lg border border-[#EDE7F6] bg-white p-5 transition-all hover:-translate-y-0.5 hover:border-[#9B59B6] hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
              >
                <span className="font-heading text-lg font-semibold text-[#333] group-hover:text-[#6B2FA0]">
                  {child.label}
                </span>
                <ArrowRight size={18} aria-hidden="true" className="text-[#6B2FA0] transition-transform group-hover:translate-x-1" />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
