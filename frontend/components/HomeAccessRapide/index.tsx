import {
  ExternalLink,
  BookOpen,
  GraduationCap,
  FileSignature,
  ArrowUpRight,
  type LucideIcon,
} from 'lucide-react';
import { getTranslations } from 'next-intl/server';

interface Card {
  key: 'foad' | 'biblio' | 'candidature';
  href: string;
  external: boolean;
  Icon: LucideIcon;
  /** Direction visuelle de la card. */
  variant: 'violet' | 'lavande' | 'or';
}

/**
 * Bloc 3 accès rapides — refonte PR R.
 *
 * 3 grandes cards (FOAD, Bibliothèque, Candidature) avec background gradient
 * thématique, halo radial décoratif, hover scale + reveal arrow ArrowUpRight,
 * shadow elevated → floating au hover. Card "candidature" mise en avant
 * avec gradient violet→or.
 */
export async function HomeAccessRapide(): Promise<JSX.Element> {
  const t = await getTranslations('home.access');

  const cards: ReadonlyArray<Card> = [
    {
      key: 'foad',
      href: process.env.NEXT_PUBLIC_FOAD_URL ?? 'https://foad.pssfp.net',
      external: true,
      Icon: GraduationCap,
      variant: 'lavande',
    },
    {
      key: 'biblio',
      href: process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#',
      external: true,
      Icon: BookOpen,
      variant: 'violet',
    },
    {
      key: 'candidature',
      href: process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#',
      external: true,
      Icon: FileSignature,
      variant: 'or',
    },
  ];

  return (
    <section
      aria-labelledby="access-heading"
      data-testid="home-access"
      className="relative overflow-hidden bg-gradient-lavande-blanc"
    >
      {/* Halos décoratifs en background */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -left-32 top-1/2 h-96 w-96 -translate-y-1/2 rounded-full opacity-20 blur-3xl"
        style={{ background: 'radial-gradient(circle, #6B2FA0 0%, transparent 70%)' }}
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-32 bottom-0 h-96 w-96 rounded-full opacity-15 blur-3xl"
        style={{ background: 'radial-gradient(circle, #C9A227 0%, transparent 70%)' }}
      />

      <div className="relative mx-auto max-w-7xl px-6 py-20 md:py-24">
        <header className="mb-12 max-w-3xl">
          <h2
            id="access-heading"
            className="font-heading font-bold text-pssfp-h2 text-[#1A0A2E]"
          >
            {t('title')}
          </h2>
          <p className="mt-4 pssfp-lead">{t('intro')}</p>
        </header>

        <ul className="grid gap-6 md:grid-cols-3">
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

function AccessCard({ card, title, body, openLabel }: AccessCardProps): JSX.Element {
  const baseStyles = {
    violet:
      'bg-gradient-violet text-white border-transparent shadow-pssfp-elevated hover:shadow-pssfp-floating',
    or:
      'bg-gradient-violet-or text-white border-transparent shadow-pssfp-elevated hover:shadow-pssfp-floating',
    lavande:
      'bg-white border-[#EDE7F6] text-[#1A0A2E] shadow-pssfp-soft hover:shadow-pssfp-elevated hover:border-[#9B59B6]/40',
  } as const;

  const isLight = card.variant === 'lavande';
  const Icon = card.Icon;

  return (
    <a
      href={card.href}
      data-testid={`access-${card.key}`}
      rel={card.external ? 'noopener noreferrer' : undefined}
      target={card.external ? '_blank' : undefined}
      className={`group relative flex h-full min-h-[260px] flex-col justify-between overflow-hidden rounded-pssfp-card border p-7 transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 ${baseStyles[card.variant]}`}
    >
      {/* Halo décoratif subtil au hover */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-16 -top-16 h-48 w-48 rounded-full opacity-0 blur-2xl transition-opacity duration-500 group-hover:opacity-50"
        style={{
          background: isLight
            ? 'radial-gradient(circle, rgba(155, 89, 182, 0.4) 0%, transparent 70%)'
            : 'radial-gradient(circle, rgba(255, 255, 255, 0.3) 0%, transparent 70%)',
        }}
      />

      <div className="relative">
        <span
          aria-hidden="true"
          className={`inline-flex h-14 w-14 items-center justify-center rounded-2xl transition-transform duration-300 ease-pssfp-out-expo group-hover:scale-110 ${
            isLight
              ? 'bg-[#EDE7F6] text-[#6B2FA0]'
              : 'bg-white/15 text-white backdrop-blur-2xl'
          }`}
        >
          <Icon size={28} />
        </span>
        <h3 className={`mt-6 font-heading text-pssfp-h3 font-bold leading-tight ${isLight ? 'text-[#1A0A2E]' : 'text-white'}`}>
          {title}
        </h3>
        <p
          className={`mt-3 text-sm leading-relaxed ${
            isLight ? 'text-[#555]' : 'text-white/85'
          }`}
        >
          {body}
        </p>
      </div>

      <div
        className={`relative mt-6 inline-flex items-center justify-between gap-3 ${
          isLight ? 'text-[#6B2FA0]' : 'text-white'
        }`}
      >
        <span className="inline-flex items-center gap-2 text-sm font-semibold">
          {openLabel}
          {card.external ? <ExternalLink size={14} aria-hidden="true" /> : null}
        </span>
        <span
          aria-hidden="true"
          className={`flex h-10 w-10 items-center justify-center rounded-full transition-all duration-300 ease-pssfp-out-expo group-hover:scale-110 ${
            isLight
              ? 'bg-[#EDE7F6] text-[#6B2FA0] group-hover:bg-[#6B2FA0] group-hover:text-white'
              : 'bg-white/15 text-white group-hover:bg-white group-hover:text-[#6B2FA0]'
          }`}
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
