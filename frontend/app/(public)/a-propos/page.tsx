import Link from 'next/link';
import { ArrowRight, BookOpen, Globe2, ShieldCheck } from 'lucide-react';
import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { getMenu } from '@/lib/api/pages';
import { RevealOnScroll } from '@/components/RevealOnScroll';

export const revalidate = 300;

export const metadata: Metadata = {
  title: 'À propos de nous',
  description:
    "Découvrez le Programme Supérieur de Spécialisation en Finances Publiques — mission, gouvernance, partenaires et conformité CAMES.",
};

/**
 * /a-propos — page d'accueil de la rubrique institutionnelle.
 *
 * Sprint S5 PR W : rubrique renommée « À propos de nous » (ex « Le PSSFP »).
 * PR U (UX Boost Phase 2) : refonte storytelling avec hero éditorial,
 * 3 piliers, scroll reveal des sous-pages CMS, CTA candidature.
 */
export default async function AProposIndexPage(): Promise<JSX.Element> {
  const t = await getTranslations('aproposIndex');
  const result = await getMenu();
  const children = result.ok
    ? (result.data.find((node) => node.slug === 'a-propos')?.children ?? [])
    : [];

  return (
    <article>
      {/* HERO ÉDITORIAL — display typo, gradient subtil, motif géométrique */}
      <header className="relative overflow-hidden border-b border-[var(--pssfp-border)] bg-gradient-lavande-blanc dark:bg-[#1A0A2E] dark:bg-none">
        {/* Motif décoratif (compositor-friendly, transform/opacity uniquement) */}
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-0 opacity-50 dark:opacity-30">
          <div className="absolute -top-24 -right-24 h-96 w-96 rounded-full bg-[#9B59B6]/15 blur-3xl dark:bg-[#B084E8]/10" />
          <div className="absolute -bottom-32 -left-16 h-[28rem] w-[28rem] rounded-full bg-[#C9A227]/10 blur-3xl dark:bg-[#E8C868]/10" />
          <svg
            className="absolute right-6 top-10 hidden h-72 w-72 text-[#6B2FA0]/10 dark:text-[#B084E8]/15 md:block"
            viewBox="0 0 200 200"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path d="M10 150 Q 50 100 100 130 T 190 80" stroke="currentColor" strokeWidth="1.5" fill="none" />
            <path d="M10 170 Q 60 120 110 150 T 190 110" stroke="currentColor" strokeWidth="1" fill="none" />
            <circle cx="40" cy="140" r="3" fill="currentColor" />
            <circle cx="100" cy="130" r="3" fill="currentColor" />
            <circle cx="160" cy="95" r="3" fill="currentColor" />
          </svg>
        </div>

        <div className="relative mx-auto max-w-5xl px-6 py-20 md:py-28">
          <RevealOnScroll>
            <p className="pssfp-eyebrow">{t('eyebrow')}</p>
          </RevealOnScroll>
          <RevealOnScroll delay={120}>
            <h1 className="mt-4 font-heading text-pssfp-h1 font-bold leading-[1.05] tracking-tight text-[var(--pssfp-text-strong)]">
              {t('title')}
              <span className="block italic font-normal pssfp-text-gradient-violet-or">
                — {t('titleAccent')}
              </span>
            </h1>
          </RevealOnScroll>
          <RevealOnScroll delay={240}>
            <p className="mt-8 max-w-2xl text-pssfp-lead text-[var(--pssfp-text-muted)]">
              {t('lead')}
            </p>
          </RevealOnScroll>
        </div>
      </header>

      {/* MISSION — paragraphe long format, alignement éditorial */}
      <section className="border-b border-[var(--pssfp-border)] bg-[var(--pssfp-bg)] pssfp-section">
        <div className="mx-auto grid max-w-5xl gap-10 px-6 md:grid-cols-12">
          <RevealOnScroll className="md:col-span-4" as="div">
            <p className="pssfp-eyebrow">{t('missionEyebrow')}</p>
            <h2 className="mt-3 font-heading text-pssfp-h2 font-bold tracking-tight text-[var(--pssfp-text-strong)]">
              {t('missionTitle')}
            </h2>
          </RevealOnScroll>
          <RevealOnScroll className="md:col-span-8" delay={150} as="div">
            <p className="text-pssfp-lead leading-relaxed text-[var(--pssfp-text)]">
              {t('missionBody')}
            </p>
          </RevealOnScroll>
        </div>
      </section>

      {/* TROIS PILIERS — bento simplifié, cards reveal staggered */}
      <section className="border-b border-[var(--pssfp-border)] bg-[var(--pssfp-bg-subtle)] pssfp-section">
        <div className="mx-auto max-w-6xl px-6">
          <RevealOnScroll>
            <p className="pssfp-eyebrow">{t('pillarsEyebrow')}</p>
            <h2 className="mt-3 max-w-2xl font-heading text-pssfp-h2 font-bold tracking-tight text-[var(--pssfp-text-strong)]">
              {t('pillarsTitle')}
            </h2>
          </RevealOnScroll>

          <ul className="mt-12 grid gap-6 md:grid-cols-3">
            {[
              { Icon: BookOpen, title: t('pillar1Title'), body: t('pillar1Body') },
              { Icon: Globe2, title: t('pillar2Title'), body: t('pillar2Body') },
              { Icon: ShieldCheck, title: t('pillar3Title'), body: t('pillar3Body') },
            ].map((p, idx) => (
              <RevealOnScroll
                key={p.title}
                delay={idx * 120}
                as="li"
                className="group relative overflow-hidden rounded-pssfp-card border border-[var(--pssfp-border)] bg-[var(--pssfp-bg-elevated)] p-7 shadow-pssfp-soft transition-all duration-300 hover:-translate-y-1 hover:border-[#6B2FA0] hover:shadow-pssfp-elevated dark:hover:border-[#B084E8]"
              >
                <span
                  aria-hidden="true"
                  className="absolute inset-x-0 top-0 h-1 bg-gradient-violet-or opacity-0 transition-opacity duration-300 group-hover:opacity-100"
                />
                <span className="inline-flex h-11 w-11 items-center justify-center rounded-md bg-[var(--pssfp-surface)] text-[var(--pssfp-primary)]">
                  <p.Icon size={22} aria-hidden="true" strokeWidth={1.75} />
                </span>
                <h3 className="mt-5 font-heading text-pssfp-h3 font-semibold text-[var(--pssfp-text-strong)]">
                  {p.title}
                </h3>
                <p className="mt-3 text-pssfp-body leading-relaxed text-[var(--pssfp-text-muted)]">
                  {p.body}
                </p>
              </RevealOnScroll>
            ))}
          </ul>
        </div>
      </section>

      {/* EXPLORER — sommaire des sous-pages CMS, cards reveal */}
      <section className="border-b border-[var(--pssfp-border)] bg-[var(--pssfp-bg)] pssfp-section">
        <div className="mx-auto max-w-5xl px-6">
          <RevealOnScroll>
            <p className="pssfp-eyebrow">{t('exploreEyebrow')}</p>
            <h2 className="mt-3 font-heading text-pssfp-h2 font-bold tracking-tight text-[var(--pssfp-text-strong)]">
              {t('exploreTitle')}
            </h2>
            <p className="mt-4 max-w-2xl text-pssfp-lead text-[var(--pssfp-text-muted)]">
              {t('exploreLead')}
            </p>
          </RevealOnScroll>

          {children.length === 0 ? (
            <p
              role="alert"
              className="mt-10 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200"
              data-testid="apropos-empty"
            >
              Le contenu institutionnel sera publié dès l'activation du CMS Filament en production.
            </p>
          ) : (
            <ul className="mt-10 grid gap-4 md:grid-cols-2">
              {children.map((child, idx) => (
                <RevealOnScroll
                  key={child.slug}
                  delay={idx * 80}
                  as="li"
                >
                  <Link
                    href={`/${child.slug}`}
                    data-testid={`apropos-card-${child.slug.split('/').pop()}`}
                    className="group flex h-full items-center justify-between gap-4 rounded-pssfp-card border border-[var(--pssfp-border)] bg-[var(--pssfp-bg-elevated)] p-5 transition-all hover:-translate-y-0.5 hover:border-[#9B59B6] hover:shadow-pssfp-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 dark:hover:border-[#B084E8] dark:focus-visible:ring-[#E8C868]"
                  >
                    <span className="font-heading text-lg font-semibold text-[var(--pssfp-text-strong)] group-hover:text-[var(--pssfp-primary)]">
                      {child.label}
                    </span>
                    <ArrowRight
                      size={18}
                      aria-hidden="true"
                      className="shrink-0 text-[var(--pssfp-primary)] transition-transform group-hover:translate-x-1"
                    />
                  </Link>
                </RevealOnScroll>
              ))}
            </ul>
          )}
        </div>
      </section>

      {/* CTA CANDIDATURE — bandeau de fin */}
      <section className="bg-[var(--pssfp-bg-subtle)] pssfp-section-tight">
        <div className="mx-auto max-w-5xl px-6">
          <RevealOnScroll>
            <div className="relative overflow-hidden rounded-pssfp-card border border-[var(--pssfp-border)] bg-gradient-violet p-8 text-white shadow-pssfp-elevated md:p-12">
              <div
                aria-hidden="true"
                className="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-white/10 blur-3xl"
              />
              <div className="relative flex flex-col items-start gap-6 md:flex-row md:items-center md:justify-between">
                <div>
                  <h2 className="font-heading text-pssfp-h2 font-bold tracking-tight">
                    {t('ctaTitle')}
                  </h2>
                  <p className="mt-3 max-w-2xl text-pssfp-body text-white/85">
                    {t('ctaBody')}
                  </p>
                </div>
                <a
                  href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
                  data-testid="apropos-cta-candidature"
                  className="inline-flex items-center gap-2 rounded-pssfp-button bg-[#C9A227] px-5 py-3 font-medium text-[#1F1A24] shadow-pssfp-elevated transition-all hover:-translate-y-0.5 hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#6B2FA0]"
                >
                  {t('ctaButton')}
                  <ArrowRight size={16} aria-hidden="true" />
                </a>
              </div>
            </div>
          </RevealOnScroll>
        </div>
      </section>
    </article>
  );
}
