import Link from 'next/link';
import {
  ArrowRight,
  Award,
  BadgeCheck,
  Banknote,
  ClipboardList,
  GraduationCap,
  Layers,
} from 'lucide-react';
import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { InternalPageCta } from '@/components/InternalPageCta';
import { InternalPageHero } from '@/components/InternalPageHero';
import { PageRenderer } from '@/components/PageRenderer';
import { getPageBySlug } from '@/lib/api/pages';

export const revalidate = 300;

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('formationsIndex');
  return { title: t('metaTitle'), description: t('metaDescription') };
}

const QUICK_LINKS = [
  { slug: 'formations/master', labelKey: 'master', Icon: GraduationCap },
  { slug: 'formations/specialites', labelKey: 'specialites', Icon: Layers },
  { slug: 'formations/formation-continue', labelKey: 'continue', Icon: ClipboardList },
  { slug: 'formations/certifications', labelKey: 'certifications', Icon: BadgeCheck },
  { slug: 'formations/seminaires', labelKey: 'seminaires', Icon: Award },
  { slug: 'formations/admission', labelKey: 'admission', Icon: ClipboardList },
  { slug: 'formations/frais-de-scolarite', labelKey: 'frais', Icon: Banknote },
] as const;

export default async function FormationsIndexPage(): Promise<JSX.Element> {
  const t = await getTranslations('formationsIndex');
  const result = await getPageBySlug('formations');

  return (
    <>
      <InternalPageHero
        eyebrow={t('eyebrow')}
        title={t('title')}
        excerpt={result.ok ? result.data.excerpt : undefined}
      />

      <div className="mx-auto max-w-5xl px-6 py-12 md:py-16">
      <ul className="grid gap-4 md:grid-cols-2">
        {QUICK_LINKS.map(({ slug, labelKey, Icon }) => (
          <li key={slug}>
            <Link
              href={`/${slug}`}
              data-testid={`formations-link-${slug.split('/').pop()}`}
              className="group flex h-full items-center gap-4 rounded-pssfp-card border border-[var(--pssfp-border)] bg-[var(--pssfp-bg-elevated)] p-5 shadow-pssfp-soft transition-all hover:-translate-y-0.5 hover:border-pssfp-prune hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 dark:hover:border-[#B084E8]"
            >
              <span className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-[var(--pssfp-primary-soft)] text-pssfp-prune dark:text-[#B084E8]">
                <Icon size={20} strokeWidth={1.75} aria-hidden="true" />
              </span>
              <span className="grow font-heading text-lg font-semibold text-[var(--pssfp-text-strong)] group-hover:text-pssfp-prune dark:group-hover:text-[#B084E8]">
                {t(`links.${labelKey}`)}
              </span>
              <ArrowRight size={18} aria-hidden="true" className="shrink-0 text-pssfp-or transition-transform group-hover:translate-x-1" />
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
