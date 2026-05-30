import { getTranslations } from 'next-intl/server';
import { Marquee } from '../magic-ui/marquee';

interface Partenaire {
  slug: string;
  name: string;
  logo: string;
}

/**
 * Logos partenaires — refonte PR R en Marquee défilant.
 *
 * Logos en niveaux de gris au repos, couleur au hover. Auto-scroll lent
 * (45s pour faire le tour complet). Ralenti au survol pour lecture
 * (`pauseOnHover`). Désactivé en prefers-reduced-motion.
 *
 * <!-- TODO ajouter MINFI SVG officiel + AFD logo + EDX logo + IFM logo
 *      quand reçus, cf. docs/assets-checklist.md §2.2 -->
 */
const PARTENAIRES: ReadonlyArray<Partenaire> = [
  { slug: 'minfi', name: 'Ministère des Finances', logo: '/logos/partners/minfi.svg' },
  { slug: 'minesup', name: 'Ministère de l\'Enseignement Supérieur', logo: '/logos/partners/minesup.png' },
  { slug: 'uy2', name: 'Université de Yaoundé II', logo: '/logos/partners/uy2.png' },
  { slug: 'expertise-france', name: 'Expertise France', logo: '/logos/partners/expertise-france.png' },
  { slug: 'fmi', name: 'Fonds Monétaire International', logo: '/logos/partners/fmi.png' },
  { slug: 'mef-maroc', name: 'Institut des Finances du Maroc', logo: '/logos/partners/mef-maroc.png' },
  { slug: 'assemblee-cm', name: 'Assemblée Nationale du Cameroun', logo: '/logos/partners/assemblee-cm.png' },
];

export async function HomePartenaires(): Promise<JSX.Element> {
  const t = await getTranslations('home.partenaires');
  return (
    <section
      aria-labelledby="partenaires-heading"
      data-testid="home-partenaires"
      className="relative overflow-hidden bg-[#FAF7F2] dark:bg-[#14101A]"
    >
      <div className="mx-auto max-w-7xl px-6 py-20 md:py-24">
        <header className="mb-12 max-w-3xl">
          <p className="pssfp-eyebrow">{t('eyebrow')}</p>
          <h2
            id="partenaires-heading"
            className="mt-3 font-heading font-bold text-pssfp-h2 text-[#14101A] dark:text-[#F5EFE3]"
          >
            {t('title')}
          </h2>
          <p className="mt-4 pssfp-lead dark:text-[#B5A8C8]">{t('intro')}</p>
        </header>
      </div>

      {/* Marquee infinie en pleine largeur, ml-cap fade sur les bords */}
      <div className="relative pb-20 md:pb-24">
        {/* Fade gauche/droite pour bord propre */}
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-y-0 left-0 z-10 w-24 bg-gradient-to-r from-[#FAF7F2] to-transparent dark:from-[#14101A]"
        />
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-y-0 right-0 z-10 w-24 bg-gradient-to-l from-[#FAF7F2] to-transparent dark:from-[#14101A]"
        />

        <Marquee pauseOnHover duration={45} className="[--gap:2.5rem]">
          {PARTENAIRES.map((p) => (
            <PartnerLogo key={p.slug} partner={p} />
          ))}
        </Marquee>

        {/* Liste a11y cachée pour AT et tests */}
        <ul className="sr-only" aria-label="Liste des partenaires">
          {PARTENAIRES.map((p) => (
            <li key={p.slug}>{p.name}</li>
          ))}
        </ul>
      </div>
    </section>
  );
}

function PartnerLogo({ partner }: { partner: Partenaire }): JSX.Element {
  return (
    <div
      className="group flex h-24 w-44 shrink-0 items-center justify-center rounded-pssfp-card border border-[#D8C9A6] bg-white p-5 shadow-pssfp-soft transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-0.5 hover:border-[#D4AF6A]/60 hover:shadow-pssfp-elevated dark:border-[#3A2F48] dark:bg-[#1F1A28] dark:hover:border-[#D4AF6A]/60"
      title={partner.name}
    >
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={partner.logo}
        alt={partner.name}
        loading="lazy"
        decoding="async"
        className="max-h-12 max-w-full object-contain grayscale transition-all duration-300 ease-pssfp-out-expo group-hover:scale-105 group-hover:grayscale-0 dark:brightness-110 dark:contrast-90"
      />
    </div>
  );
}
