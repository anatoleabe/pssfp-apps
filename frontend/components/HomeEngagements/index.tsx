import { Award, Lightbulb, Target, type LucideIcon } from 'lucide-react';
import { getTranslations } from 'next-intl/server';

/**
 * Section "Nos engagements" — 3 cards éditoriales (Sprint S5.1).
 *
 * Référence : maquette identité visuelle 2026 (ADR-0008).
 *   - Rigueur et qualité
 *   - Innovation pédagogique
 *   - Impact durable
 */
interface Engagement {
  key: 'rigueur' | 'innovation' | 'impact';
  Icon: LucideIcon;
  /** Numéro éditorial pour parité hiérarchique avec HomePiliers (S5.4). */
  number: '01' | '02' | '03';
}

const ENGAGEMENTS: ReadonlyArray<Engagement> = [
  { key: 'rigueur', Icon: Award, number: '01' },
  { key: 'innovation', Icon: Lightbulb, number: '02' },
  { key: 'impact', Icon: Target, number: '03' },
];

export async function HomeEngagements(): Promise<JSX.Element> {
  const t = await getTranslations('home.engagements');
  return (
    <section
      aria-labelledby="engagements-heading"
      data-testid="home-engagements"
      className="relative border-y border-[var(--pssfp-border)] bg-[var(--pssfp-bg)]"
    >
      <div className="mx-auto max-w-7xl px-6 py-16 md:py-24">
        <header className="mx-auto mb-12 max-w-2xl text-center md:mb-14">
          <p className="pssfp-eyebrow">{t('eyebrow')}</p>
          <h2
            id="engagements-heading"
            className="mt-4 font-heading text-pssfp-h2 font-bold text-[var(--pssfp-text-strong)]"
          >
            {t('heading')}
          </h2>
          <p className="mx-auto mt-5 max-w-2xl text-pssfp-body text-[var(--pssfp-text-muted)]">
            {t('intro')}
          </p>
        </header>

        <ul className="grid gap-6 md:grid-cols-3 md:gap-8">
          {ENGAGEMENTS.map((e) => (
            <li
              key={e.key}
              className="group relative flex flex-col rounded-pssfp-card border border-[var(--pssfp-border)] bg-[var(--pssfp-bg-elevated)] p-8 shadow-pssfp-soft transition-all duration-300 hover:-translate-y-1 hover:border-pssfp-prune hover:shadow-pssfp-elevated"
            >
              {/* Hairline or supérieur révélée au hover */}
              <span
                aria-hidden="true"
                className="absolute inset-x-0 top-0 h-1 rounded-t-pssfp-card bg-gradient-prune-or opacity-0 transition-opacity duration-300 group-hover:opacity-100"
              />
              {/*
                Eyebrow numéroté éditorial — parité hiérarchique avec
                HomePiliers (S5.4) : chaque pilier/engagement = un focal point
                ancré par son numéro en haut-droite, calque DM Sans tracking.
              */}
              <span
                aria-hidden="true"
                className="absolute right-6 top-6 font-ui text-[10px] font-semibold uppercase tracking-[0.24em] text-pssfp-or"
              >
                {e.number}
              </span>
              {/* Icône en cercle prune avec accent or */}
              <span
                aria-hidden="true"
                className="inline-flex h-14 w-14 items-center justify-center rounded-full bg-[var(--pssfp-primary-soft)] text-pssfp-prune transition-all duration-300 group-hover:bg-pssfp-prune group-hover:text-pssfp-or dark:text-[#B084E8]"
              >
                <e.Icon size={26} strokeWidth={1.5} />
              </span>
              <h3 className="mt-6 font-heading text-xl font-semibold text-[var(--pssfp-text-strong)]">
                {t(`${e.key}.title`)}
              </h3>
              {/* Micro-séparateur or — densifie l'espace entre titre et body. */}
              <span
                aria-hidden="true"
                className="mt-3 inline-block h-px w-8 bg-pssfp-or/60 transition-all duration-300 group-hover:w-12 group-hover:bg-pssfp-or"
              />
              <p className="mt-3 text-pssfp-body leading-relaxed text-[var(--pssfp-text-muted)]">
                {t(`${e.key}.body`)}
              </p>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
