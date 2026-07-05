import Link from 'next/link';
import { ArrowRight, GraduationCap } from 'lucide-react';
import { getTranslations } from 'next-intl/server';

export async function InternalPageCta(): Promise<JSX.Element> {
  const t = await getTranslations('internalCta');

  return (
    <section className="border-y border-[var(--pssfp-border)] bg-[var(--pssfp-bg-subtle)] py-10 md:py-12">
      <div className="mx-auto flex max-w-6xl flex-col gap-6 px-6 md:flex-row md:items-center md:justify-between">
        <div className="flex max-w-3xl items-start gap-4">
          <span className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-pssfp-prune text-white dark:bg-[#B084E8] dark:text-[#14101A]">
            <GraduationCap size={23} strokeWidth={1.75} aria-hidden="true" />
          </span>
          <div>
            <p className="pssfp-eyebrow">{t('eyebrow')}</p>
            <h2 className="mt-1 font-heading text-2xl font-bold text-[var(--pssfp-text-strong)] md:text-3xl">
              {t('title')}
            </h2>
            <p className="mt-2 text-sm leading-relaxed text-[var(--pssfp-text-muted)] md:text-base">
              {t('body')}
            </p>
          </div>
        </div>
        <div className="flex flex-wrap gap-3 md:justify-end">
          <Link
            href="/formations"
            className="inline-flex h-11 items-center rounded-pssfp-button border border-[var(--pssfp-border)] bg-[var(--pssfp-bg-elevated)] px-4 text-sm font-medium text-[var(--pssfp-text)] transition-colors hover:border-pssfp-prune hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 dark:hover:border-[#B084E8] dark:hover:text-[#B084E8]"
          >
            {t('secondary')}
          </Link>
          <a
            href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
            className="group inline-flex h-11 items-center gap-2 rounded-pssfp-button bg-pssfp-prune px-4 text-sm font-semibold text-white transition-colors hover:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2 dark:bg-[#B084E8] dark:text-[#14101A] dark:hover:bg-[#C9A0F0]"
          >
            {t('primary')}
            <ArrowRight
              size={15}
              aria-hidden="true"
              className="transition-transform group-hover:translate-x-0.5"
            />
          </a>
        </div>
      </div>
    </section>
  );
}
