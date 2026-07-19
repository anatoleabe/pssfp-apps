import Link from 'next/link';
import {
  ArrowRight,
  BookOpen,
  Globe2,
  ShieldCheck,
  UserSquare2,
  Building2,
  Users,
  Network,
  FileSignature,
  History,
  School,
  Award,
  Handshake,
} from 'lucide-react';
import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { getMenu } from '@/lib/api/pages';
import { RevealOnScroll } from '@/components/RevealOnScroll';
import { BackgroundPaths } from '@/components/magic-ui/background-paths';

export const revalidate = 300;

export const metadata: Metadata = {
  title: 'À propos de nous',
  description:
    "Découvrez le Programme Supérieur de Spécialisation en Finances Publiques — mission, gouvernance, partenaires et conformité CAMES.",
};

// Carte d'icônes par slug — utilisé pour les cards de navigation des sous-pages.
const ICON_BY_SLUG: Record<string, typeof BookOpen> = {
  'a-propos/mot-president': UserSquare2,
  'a-propos/presentation': BookOpen,
  'a-propos/comite-pilotage': Users,
  'a-propos/organigramme': Network,
  'a-propos/convention-tripartite': FileSignature,
  'a-propos/histoire': History,
  'a-propos/infrastructure': Building2,
  'a-propos/partenaires': Handshake,
  'a-propos/conformite-cames': Award,
};

// Description courte par sous-page — pour les cards.
const SHORT_DESC_BY_SLUG: Record<string, string> = {
  'a-propos/mot-president': "Mot d'introduction du Président du Comité de Pilotage.",
  'a-propos/presentation': "Mission, vocation et offre de formation du PSSFP.",
  'a-propos/comite-pilotage': "Composition et missions du COPIL.",
  'a-propos/organigramme': "Structure organisationnelle complète.",
  'a-propos/convention-tripartite': "Cadre juridique MINFI · MINESUP · UY2.",
  'a-propos/histoire': "De 2013 aux 13 promotions diplômées.",
  'a-propos/infrastructure': "Campus de Messa, amphithéâtres, bibliothèque.",
  'a-propos/partenaires': "Partenaires nationaux et internationaux.",
  'a-propos/conformite-cames': "12 exigences CAMES — accréditation régionale.",
};

/**
 * /a-propos — page d'accueil de la rubrique institutionnelle.
 *
 * Sprint S5.1 — refonte cards avec icônes lucide + descriptions courtes,
 * conforme à la maquette identité visuelle 2026 (ADR-0008).
 */
export default async function AProposIndexPage(): Promise<JSX.Element> {
  const t = await getTranslations('aproposIndex');
  const result = await getMenu();
  // Defensive : l'endpoint peut renvoyer un payload non-Array (ex. backend
  // en mode stub `{status:'ok'}`). Cf. fix HomeActualites S5.3 (commit 51f7854).
  const children = (
    result.ok && Array.isArray(result.data)
      ? (result.data.find((node) => node.slug === 'a-propos')?.children ?? [])
      : []
  );

  return (
    <article>
      {/* HERO ÉDITORIAL — display typo, accent plat, motif géométrique */}
      <header className="relative overflow-hidden border-b border-[var(--pssfp-border)] bg-[#FAF7F2] dark:bg-[#1A1A1A]">
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-0 opacity-50 dark:opacity-30">
          <div className="absolute -top-24 -right-24 h-96 w-96 rounded-full bg-[var(--pssfp-prune-light)]/15 blur-3xl dark:bg-[#B084E8]/10" />
          <div className="absolute -bottom-32 -left-16 h-[28rem] w-[28rem] rounded-full bg-[var(--pssfp-or)]/10 blur-3xl dark:bg-[#E5C788]/10" />
          <BackgroundPaths className="opacity-70 dark:opacity-50" />
        </div>

        <div className="relative mx-auto max-w-5xl px-6 py-20 md:py-28">
          <RevealOnScroll>
            <p className="pssfp-eyebrow">{t('eyebrow')}</p>
          </RevealOnScroll>
          <RevealOnScroll delay={120}>
            <h1 className="mt-4 font-heading text-pssfp-h1 font-bold leading-[1.05] tracking-tight text-[var(--pssfp-text-strong)]">
              {t('title')}
              <span className="block italic font-normal text-[#4A2E67] dark:text-[#E5C788]">
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
                className="group relative overflow-hidden rounded-pssfp-card border border-[var(--pssfp-border)] bg-[var(--pssfp-bg-elevated)] p-7 shadow-pssfp-soft transition-all duration-300 hover:-translate-y-1 hover:border-pssfp-prune hover:shadow-pssfp-elevated dark:hover:border-[#B084E8]"
              >
                <span
                  aria-hidden="true"
                  className="absolute inset-x-0 top-0 h-1 bg-[#4A2E67] opacity-0 transition-opacity duration-300 group-hover:opacity-100"
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

      {/* EXPLORER — grid 3 colonnes avec icônes + description courte par sous-page */}
      <section className="border-b border-[var(--pssfp-border)] bg-[var(--pssfp-bg)] pssfp-section">
        <div className="mx-auto max-w-6xl px-6">
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
            <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
              {children.map((child, idx) => {
                const Icon = ICON_BY_SLUG[child.slug] ?? School;
                const desc = SHORT_DESC_BY_SLUG[child.slug];
                const key = child.slug.split('/').pop() ?? child.slug;
                return (
                  <RevealOnScroll key={child.slug} delay={idx * 60} as="li">
                    <Link
                      href={`/${child.slug}`}
                      data-testid={`apropos-card-${key}`}
                      className="group relative flex h-full flex-col rounded-pssfp-card border border-[var(--pssfp-border)] bg-[var(--pssfp-bg-elevated)] p-6 transition-all duration-300 hover:-translate-y-0.5 hover:border-pssfp-prune hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 dark:hover:border-[#B084E8] dark:focus-visible:ring-[#E5C788]"
                    >
                      <span
                        aria-hidden="true"
                        className="absolute inset-x-0 top-0 h-0.5 bg-[#4A2E67] opacity-0 transition-opacity duration-300 group-hover:opacity-100"
                      />
                      <span className="inline-flex h-11 w-11 items-center justify-center rounded-full bg-[var(--pssfp-primary-soft)] text-pssfp-prune dark:text-[#B084E8]">
                        <Icon size={20} aria-hidden="true" strokeWidth={1.75} />
                      </span>
                      <h3 className="mt-5 font-heading text-lg font-semibold text-[var(--pssfp-text-strong)] group-hover:text-pssfp-prune dark:group-hover:text-[#B084E8]">
                        {child.label}
                      </h3>
                      {desc && (
                        <p className="mt-2 text-sm leading-relaxed text-[var(--pssfp-text-muted)]">
                          {desc}
                        </p>
                      )}
                      <span className="mt-auto pt-4 inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-pssfp-or">
                        Découvrir
                        <ArrowRight
                          size={14}
                          aria-hidden="true"
                          className="transition-transform group-hover:translate-x-1"
                        />
                      </span>
                    </Link>
                  </RevealOnScroll>
                );
              })}
            </ul>
          )}
        </div>
      </section>

      {/* CTA CANDIDATURE — bandeau de fin */}
      <section className="bg-[var(--pssfp-bg-subtle)] pssfp-section-tight">
        <div className="mx-auto max-w-5xl px-6">
          <RevealOnScroll>
            <div className="relative overflow-hidden rounded-pssfp-card border border-[var(--pssfp-border)] bg-[#4A2E67] p-8 text-white shadow-pssfp-elevated md:p-12">
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
                  className="inline-flex items-center gap-2 rounded-pssfp-button bg-pssfp-or px-5 py-3 font-medium text-pssfp-graphite shadow-pssfp-elevated transition-all hover:-translate-y-0.5 hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-prune"
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
