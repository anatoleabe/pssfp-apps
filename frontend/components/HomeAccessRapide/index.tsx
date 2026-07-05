import {
  ExternalLink,
  BookOpen,
  GraduationCap,
  FileSignature,
  ArrowUpRight,
  type LucideIcon,
} from 'lucide-react';
import { getTranslations } from 'next-intl/server';

type Variant = 'petrole' | 'prune' | 'gold';

interface Card {
  key: 'foad' | 'biblio' | 'candidature';
  href: string;
  external: boolean;
  Icon: LucideIcon;
  variant: Variant;
}

/**
 * Bloc compact d'accès rapides placé immédiatement après le hero.
 * Il sert les trois parcours transactionnels sans concurrencer la mission
 * institutionnelle affichée au-dessus.
 */
export async function HomeAccessRapide(): Promise<JSX.Element> {
  const t = await getTranslations('home.access');

  const cards: ReadonlyArray<Card> = [
    {
      key: 'foad',
      href: process.env.NEXT_PUBLIC_FOAD_URL ?? 'https://foad.pssfp.org',
      external: true,
      Icon: GraduationCap,
      variant: 'petrole',
    },
    {
      key: 'biblio',
      href: process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#',
      external: true,
      Icon: BookOpen,
      variant: 'prune',
    },
    {
      key: 'candidature',
      href: process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#',
      external: true,
      Icon: FileSignature,
      variant: 'gold',
    },
  ];

  return (
    <section
      id="acces-rapides"
      aria-labelledby="access-heading"
      data-testid="home-access"
      className="border-b border-pssfp-or/35 bg-pssfp-ivoire dark:border-pssfp-or/20 dark:bg-pssfp-prune-dark"
    >
      <div className="mx-auto max-w-7xl px-6 py-10 md:py-12">
        <header className="mb-7 flex flex-wrap items-end justify-between gap-3">
          <div>
            <p className="pssfp-eyebrow">{t('eyebrow')}</p>
            <h2
              id="access-heading"
              className="mt-2 font-heading text-3xl font-bold text-pssfp-prune-dark dark:text-white"
            >
              {t('title')}
            </h2>
          </div>
          <p className="max-w-xl text-base text-pssfp-graphite-light dark:text-white/75">{t('intro')}</p>
        </header>

        <ul className="grid gap-4 md:grid-cols-3">
          {cards.map((card) => (
            <li key={card.key}>
              <AccessCard
                card={card}
                title={t(`${card.key}Title`)}
                body={t(`${card.key}Body`)}
                openLabel={t('open')}
              />
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

interface AccessCardProps {
  card: Card;
  title: string;
  body: string;
  openLabel: string;
}

const VARIANT_STYLES: Record<Variant, {
  card: string;
  iconBg: string;
  titleColor: string;
  bodyColor: string;
  ctaColor: string;
  ctaArrowBg: string;
  focusRing: string;
}> = {
  petrole: {
    card: 'border-pssfp-bleu-petrole bg-pssfp-bleu-petrole text-white shadow-pssfp-soft hover:shadow-pssfp-elevated',
    iconBg: 'bg-white/15 text-white',
    titleColor: 'text-white',
    bodyColor: 'text-white/85',
    ctaColor: 'text-white',
    ctaArrowBg: 'bg-white/15 text-white group-hover:bg-white group-hover:text-pssfp-bleu-petrole',
    focusRing: 'focus-visible:ring-pssfp-or',
  },
  prune: {
    card: 'border-pssfp-prune bg-pssfp-prune text-white shadow-pssfp-soft hover:shadow-pssfp-elevated',
    iconBg: 'bg-white/10 text-pssfp-or-light',
    titleColor: 'text-white',
    bodyColor: 'text-white/85',
    ctaColor: 'text-pssfp-or-light',
    ctaArrowBg: 'bg-white/10 text-pssfp-or-light group-hover:bg-pssfp-or-light group-hover:text-pssfp-prune-dark',
    focusRing: 'focus-visible:ring-pssfp-or-light',
  },
  gold: {
    card: 'border-pssfp-or bg-pssfp-or text-pssfp-prune-dark shadow-pssfp-soft hover:shadow-pssfp-elevated',
    iconBg: 'bg-pssfp-prune-dark/10 text-pssfp-prune-dark',
    titleColor: 'text-pssfp-prune-dark',
    bodyColor: 'text-pssfp-prune-dark/80',
    ctaColor: 'text-pssfp-prune-dark',
    ctaArrowBg: 'bg-pssfp-prune-dark/10 text-pssfp-prune-dark group-hover:bg-pssfp-prune-dark group-hover:text-pssfp-or-light',
    focusRing: 'focus-visible:ring-pssfp-bleu-petrole',
  },
};

function AccessCard({ card, title, body, openLabel }: AccessCardProps): JSX.Element {
  const v = VARIANT_STYLES[card.variant];
  const Icon = card.Icon;

  return (
    <a
      href={card.href}
      data-testid={`access-${card.key}`}
      rel={card.external ? 'noopener noreferrer' : undefined}
      target={card.external ? '_blank' : undefined}
      className={`group flex h-full min-h-[188px] cursor-pointer flex-col justify-between rounded-pssfp-card border p-6 transition-all duration-200 ease-out hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 ${v.card} ${v.focusRing}`}
    >
      <div>
        <span
          aria-hidden="true"
          className={`inline-flex h-12 w-12 items-center justify-center rounded-xl transition-transform duration-200 ease-out group-hover:scale-105 ${v.iconBg}`}
        >
          <Icon size={24} />
        </span>
        <h3 className={`mt-4 font-heading text-pssfp-h3 font-bold leading-tight ${v.titleColor}`}>
          {title}
        </h3>
        <p className={`mt-2 text-sm leading-relaxed ${v.bodyColor}`}>{body}</p>
      </div>

      <div className={`mt-5 inline-flex items-center justify-between gap-3 ${v.ctaColor}`}>
        <span className="inline-flex items-center gap-2 text-sm font-semibold">
          {openLabel}
          {card.external ? <ExternalLink size={14} aria-hidden="true" /> : null}
        </span>
        <span
          aria-hidden="true"
          className={`flex h-9 w-9 items-center justify-center rounded-full transition-all duration-200 ease-out group-hover:scale-105 ${v.ctaArrowBg}`}
        >
          <ArrowUpRight
            size={18}
            className="transition-transform duration-300 ease-pssfp-out-expo group-hover:translate-x-0.5 group-hover:-translate-y-0.5"
          />
        </span>
      </div>
    </a>
  );
}
