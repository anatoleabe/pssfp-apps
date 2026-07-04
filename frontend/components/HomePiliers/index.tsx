import { GraduationCap, Briefcase, Globe2, ShieldCheck, type LucideIcon } from 'lucide-react';
import { getTranslations } from 'next-intl/server';

/**
 * Bandeau institutionnel bleu pétrole — 4 piliers du PSSFP (Sprint S5.1).
 *
 * Référence : maquette identité visuelle 2026 (ADR-0008), section "4 piliers".
 *   1. Formations qualifiantes
 *   2. Expertise publique
 *   3. Réseau & partenariats
 *   4. Éthique & intégrité
 *
 * Server Component (pas d'interactivité). Texte 100% via next-intl.
 */

interface Pilier {
  key: 'formations' | 'expertise' | 'reseau' | 'ethique';
  Icon: LucideIcon;
  /** Numéro éditorial — parité hiérarchique avec HomeEngagements (S5.4). */
  number: '01' | '02' | '03' | '04';
}

const PILIERS: ReadonlyArray<Pilier> = [
  { key: 'formations', Icon: GraduationCap, number: '01' },
  { key: 'expertise', Icon: Briefcase, number: '02' },
  { key: 'reseau', Icon: Globe2, number: '03' },
  { key: 'ethique', Icon: ShieldCheck, number: '04' },
];

export async function HomePiliers(): Promise<JSX.Element> {
  const t = await getTranslations('home.piliers');
  return (
    <section
      aria-labelledby="piliers-heading"
      data-testid="home-piliers"
      className="relative bg-pssfp-bleu-petrole text-white"
    >
      {/* Hairline or de bord supérieur (référence éditoriale) */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-pssfp-or/60 to-transparent"
      />
      {/* Halo or doux en fond, compositor-friendly */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 -z-0 opacity-40"
        style={{
          background:
            'radial-gradient(circle at 80% 0%, rgba(212, 175, 106, 0.18) 0%, transparent 60%)',
        }}
      />

      <div className="relative mx-auto max-w-7xl px-6 py-12 md:py-16">
        <div className="mx-auto mb-8 max-w-3xl text-center md:mb-10">
          <p className="font-ui text-xs uppercase tracking-[0.18em] text-pssfp-or">
            {t('eyebrow')}
          </p>
          <h2
            id="piliers-heading"
            className="mt-4 font-heading text-pssfp-h2 font-bold text-white"
          >
            {t('heading')}
          </h2>
          <p className="mx-auto mt-5 max-w-2xl text-pssfp-body text-white/75">
            {t('intro')}
          </p>
        </div>

        <ul className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4 lg:gap-8">
          {PILIERS.map((p) => (
            <li key={p.key} className="group relative">
              <div className="flex flex-col items-start">
                {/* Pair éditorial : icône GAUCHE + numéro DROITE — densifie. */}
                <div className="flex w-full items-center justify-between">
                  <span
                    aria-hidden="true"
                    className="inline-flex h-12 w-12 items-center justify-center rounded-full bg-pssfp-or/15 text-pssfp-or transition-all duration-300 group-hover:bg-pssfp-or group-hover:text-pssfp-bleu-petrole"
                  >
                    <p.Icon size={22} strokeWidth={1.75} />
                  </span>
                  <span
                    aria-hidden="true"
                    className="font-ui text-[10px] font-semibold uppercase tracking-[0.24em] text-pssfp-or/75"
                  >
                    {p.number}
                  </span>
                </div>
                <h3 className="mt-4 font-heading text-lg font-bold text-white">
                  {t(`${p.key}.title`)}
                </h3>
                {/* Micro-séparateur or — parité avec HomeEngagements. */}
                <span
                  aria-hidden="true"
                  className="mt-2 inline-block h-px w-8 bg-pssfp-or/60 transition-all duration-300 group-hover:w-12 group-hover:bg-pssfp-or"
                />
                <p className="mt-2 text-sm leading-relaxed text-white/75">
                  {t(`${p.key}.body`)}
                </p>
              </div>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
