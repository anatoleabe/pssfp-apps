import Link from 'next/link';
import { ArrowRight } from 'lucide-react';
import { getTranslations } from 'next-intl/server';

interface Specialite {
  slug: string;
  number: string;
  titleKey: 'spec1' | 'spec2' | 'spec3' | 'spec4' | 'spec5';
  bodyKey: 'spec1Body' | 'spec2Body' | 'spec3Body' | 'spec4Body' | 'spec5Body';
}

const SPECS: ReadonlyArray<Specialite> = [
  { slug: 'economie-publique-gestion-publique', number: '01', titleKey: 'spec1', bodyKey: 'spec1Body' },
  { slug: 'fiscalite-finance-comptabilite-publique', number: '02', titleKey: 'spec2', bodyKey: 'spec2Body' },
  { slug: 'gouvernance-territoriale-finances-publiques-locales', number: '03', titleKey: 'spec3', bodyKey: 'spec3Body' },
  { slug: 'marches-publics-partenariats-public-prive', number: '04', titleKey: 'spec4', bodyKey: 'spec4Body' },
  { slug: 'audit-controle-finances-publiques', number: '05', titleKey: 'spec5', bodyKey: 'spec5Body' },
];

export async function HomeSpecialites(): Promise<JSX.Element> {
  const t = await getTranslations('home.specialites');
  return (
    <section
      aria-labelledby="specialites-heading"
      data-testid="home-specialites"
      className="bg-[#FAF7FF]"
    >
      <div className="mx-auto max-w-7xl px-6 py-16 md:py-20">
        <header className="mb-10 max-w-3xl">
          <p className="font-ui text-sm uppercase tracking-widest text-[#C9A227]">
            {t('eyebrow')}
          </p>
          <h2
            id="specialites-heading"
            className="mt-2 font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl"
          >
            {t('title')}
          </h2>
          <p className="mt-3 text-[#555]">{t('intro')}</p>
        </header>

        <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {SPECS.map((spec) => (
            <li key={spec.slug}>
              <Link
                href={`/formations/specialites/${spec.slug}`}
                data-testid={`spec-card-${spec.slug}`}
                className="group flex h-full flex-col rounded-xl border border-[#EDE7F6] bg-white p-6 shadow-sm transition-all hover:-translate-y-1 hover:border-[#9B59B6] hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
              >
                <span className="font-heading text-2xl font-bold text-[#C9A227]">{spec.number}</span>
                <h3 className="mt-3 font-heading text-lg font-bold leading-snug text-[#333]">
                  {t(spec.titleKey)}
                </h3>
                <p className="mt-2 grow text-sm text-[#555]">{t(spec.bodyKey)}</p>
                <span className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-[#6B2FA0] group-hover:gap-2">
                  {t('readMore')}
                  <ArrowRight size={14} aria-hidden="true" className="transition-transform group-hover:translate-x-1" />
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
