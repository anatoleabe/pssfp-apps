import { getTranslations } from 'next-intl/server';

interface Partenaire {
  slug: string;
  name: string;
  logo: string;
}

/**
 * Logos partenaires — V1, copiés depuis assets-source/logos/institutions-coop/
 * dans frontend/public/logos/partners/. Si manque (placeholder), badge texte.
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
      className="bg-[#FAF7FF]"
    >
      <div className="mx-auto max-w-7xl px-6 py-16 md:py-20">
        <header className="mb-10 max-w-3xl">
          <p className="font-ui text-sm uppercase tracking-widest text-[#C9A227]">
            {t('eyebrow')}
          </p>
          <h2
            id="partenaires-heading"
            className="mt-2 font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl"
          >
            {t('title')}
          </h2>
          <p className="mt-3 text-[#555]">{t('intro')}</p>
        </header>

        <ul className="grid grid-cols-2 items-center gap-6 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-7">
          {PARTENAIRES.map((p) => (
            <li
              key={p.slug}
              className="flex h-24 items-center justify-center rounded-lg border border-[#EDE7F6] bg-white p-4 transition-all hover:border-[#9B59B6]"
              title={p.name}
            >
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={p.logo}
                alt={p.name}
                loading="lazy"
                decoding="async"
                className="max-h-12 max-w-full object-contain opacity-90 transition-opacity hover:opacity-100"
              />
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
