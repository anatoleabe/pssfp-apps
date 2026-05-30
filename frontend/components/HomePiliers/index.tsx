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
}

const PILIERS: ReadonlyArray<Pilier> = [
  { key: 'formations', Icon: GraduationCap },
  { key: 'expertise', Icon: Briefcase },
  { key: 'reseau', Icon: Globe2 },
  { key: 'ethique', Icon: ShieldCheck },
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

      <div className="relative mx-auto max-w-7xl px-6 py-16 md:py-20">
        <div className="mx-auto mb-12 max-w-3xl text-center md:mb-14">
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

        <ul className="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-4 lg:gap-10">
          {PILIERS.map((p) => (
            <li key={p.key} className="group">
              <div className="flex flex-col items-start">
                <span
                  aria-hidden="true"
                  className="inline-flex h-12 w-12 items-center justify-center rounded-full bg-pssfp-or/15 text-pssfp-or transition-all duration-300 group-hover:bg-pssfp-or group-hover:text-pssfp-bleu-petrole"
                >
                  <p.Icon size={22} strokeWidth={1.75} />
                </span>
                <h3 className="mt-5 font-heading text-lg font-bold text-white">
                  {t(`${p.key}.title`)}
                </h3>
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
