import Link from 'next/link';
import { ArrowRight } from 'lucide-react';
import { getFormatter, getTranslations } from 'next-intl/server';
import type { MotCle } from '@/lib/api/types';
import { collectionHref } from '@/lib/routes';
import { countedChildren } from '@/lib/themes';
import { Eyebrow } from './Eyebrow';

const MAX_TEXT_TYPES = 6;

interface LegalFeatureProps {
  /** Documents de la collection Législation (`stats.by_type.texte_loi`) : section masquée à 0. */
  count: number;
  /** Mots-clés comptés pour le type `texte_loi` (vide si indisponible : pas de raccourcis). */
  motsCles: MotCle[];
}

/** Illustration abstraite : feuillets superposés (aucun texte, aucun sceau, aucune imitation de document officiel). */
function PaperStack(): JSX.Element {
  const lines = (
    <span className="mt-5 flex flex-col gap-2.5">
      <span className="h-1.5 w-3/4 rounded-full bg-pssfp-lavande/45" />
      <span className="h-1.5 w-full rounded-full bg-pssfp-lavande/30" />
      <span className="h-1.5 w-5/6 rounded-full bg-pssfp-lavande/30" />
      <span className="h-1.5 w-2/3 rounded-full bg-pssfp-lavande/30" />
    </span>
  );
  return (
    <div aria-hidden="true" className="relative mx-auto hidden aspect-[4/3] w-full max-w-md md:block">
      <span className="absolute left-1/2 top-1/2 h-[118%] w-[118%] -translate-x-1/2 -translate-y-1/2 rounded-full border border-pssfp-lavande/40" />
      <span className="absolute left-[6%] top-[18%] w-[46%] -rotate-[9deg] rounded-md bg-white p-5 shadow-pssfp-elevated">
        <span className="block h-2 w-1/2 rounded-full bg-pssfp-prune/80" />
        {lines}
      </span>
      <span className="absolute right-[4%] top-[14%] w-[44%] rotate-[8deg] rounded-md bg-[#F8F1DF] p-5 shadow-pssfp-elevated">
        <span className="block h-2 w-2/5 rounded-full bg-pssfp-or" />
        {lines}
      </span>
      <span className="absolute left-[27%] top-[4%] w-[48%] rounded-md bg-white p-6 shadow-pssfp-floating">
        <span className="block h-2.5 w-3/5 rounded-full bg-pssfp-prune" />
        {lines}
        <span className="mx-auto mt-6 block h-14 w-14 rounded-full border-2 border-pssfp-prune-light/40" />
      </span>
    </div>
  );
}

/** Mise en avant de la documentation juridique : lien vers Législation et raccourcis par type de texte. */
export async function LegalFeature({ count, motsCles }: LegalFeatureProps): Promise<JSX.Element | null> {
  if (count <= 0) return null;
  const t = await getTranslations('home.legal');
  const format = await getFormatter();
  const textTypes = countedChildren(motsCles, 'types-de-textes').slice(0, MAX_TEXT_TYPES);

  return (
    <section data-testid="home-legal" aria-labelledby="legal-title" className="relative isolate overflow-hidden bg-[linear-gradient(100deg,#F1EBF6_0%,#EEE7F3_60%,#E8DEF1_100%)]">
      <div className="mx-auto grid max-w-7xl items-center gap-12 px-4 py-16 sm:px-6 sm:py-20 lg:grid-cols-2 lg:gap-16 lg:py-24">
        <div className="min-w-0">
          <Eyebrow>{t('eyebrow')}</Eyebrow>
          <h2 id="legal-title" className="mt-4 max-w-[18ch] text-balance font-heading text-pssfp-h2 font-semibold text-pssfp-prune-dark">
            {t('title')}
          </h2>
          <p className="mt-4 max-w-xl text-pssfp-graphite">{t('body', { count })}</p>
          {textTypes.length > 0 && (
            <ul aria-label={t('typesLabel')} className="mt-6 flex flex-wrap gap-2">
              {textTypes.map((m) => (
                <li key={m.slug}>
                  <Link
                    href={collectionHref('texte_loi', { mot_cle: m.slug })}
                    className="inline-flex min-h-9 items-center gap-2 rounded-full border border-pssfp-prune/40 bg-white/80 px-3.5 font-ui text-sm text-pssfp-prune-dark transition-colors hover:border-pssfp-prune hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
                  >
                    {m.label}
                    <span className="text-xs [font-variant-numeric:lining-nums_tabular-nums] text-pssfp-graphite-light">{format.number(m.documents_count)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
          <Link
            href={collectionHref('texte_loi')}
            data-testid="home-legal-cta"
            className="mt-8 inline-flex h-12 items-center gap-2 rounded-pssfp-button bg-pssfp-prune px-6 font-ui text-sm font-semibold text-white shadow-pssfp-soft transition-colors hover:bg-pssfp-prune-dark active:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
          >
            {t('cta')}
            <ArrowRight size={16} aria-hidden="true" />
          </Link>
        </div>
        <PaperStack />
      </div>
    </section>
  );
}
