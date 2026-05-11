import {
  ExternalLink,
  BookOpen,
  GraduationCap,
  FileSignature,
  ArrowUpRight,
  type LucideIcon,
} from 'lucide-react';
import { getTranslations } from 'next-intl/server';

type Variant = 'forest' | 'ink' | 'gold';

interface Card {
  key: 'foad' | 'biblio' | 'candidature';
  href: string;
  external: boolean;
  Icon: LucideIcon;
  variant: Variant;
}

/**
 * Bloc 3 accès rapides — refonte Editorial Senate.
 *
 * 3 grandes cards avec rotation forest / ink / gold pour casser la dominante
 * violette. La candidature reste la card phare (gold burnished, accent
 * institutionnel fort). FOAD prend l'ancre forest. Biblio prend ink-deep
 * pour l'élévation typographique. Background cream warm — plus de lavande
 * blanc.
 */
export async function HomeAccessRapide(): Promise<JSX.Element> {
  const t = await getTranslations('home.access');

  const cards: ReadonlyArray<Card> = [
    {
      key: 'foad',
      href: process.env.NEXT_PUBLIC_FOAD_URL ?? 'https://foad.pssfp.net',
      external: true,
      Icon: GraduationCap,
      variant: 'forest',
    },
    {
      key: 'biblio',
      href: process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#',
      external: true,
      Icon: BookOpen,
      variant: 'ink',
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
      aria-labelledby="access-heading"
      data-testid="home-access"
      className="relative overflow-hidden bg-gradient-cream-warm pssfp-paper-grain"
    >
      {/* Halo décoratifs sobres */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -left-32 top-1/2 h-96 w-96 -translate-y-1/2 rounded-full opacity-15 blur-3xl"
        style={{ background: 'radial-gradient(circle, #0E4D3F 0%, transparent 70%)' }}
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-32 bottom-0 h-96 w-96 rounded-full opacity-20 blur-3xl"
        style={{ background: 'radial-gradient(circle, #C9A227 0%, transparent 70%)' }}
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[#C9A227]/50 to-transparent"
      />

      <div className="relative mx-auto max-w-7xl px-6 py-20 md:py-24">
        <header className="mb-12 max-w-3xl">
          <h2
            id="access-heading"
            className="font-heading font-bold text-pssfp-h2 text-[#14101A]"
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

const VARIANT_STYLES: Record<Variant, {
  card: string;
  haloBg: string;
  iconBg: string;
  titleColor: string;
  bodyColor: string;
  ctaColor: string;
  ctaArrowBg: string;
  focusRing: string;
}> = {
  forest: {
    card: 'bg-gradient-forest text-white border-transparent shadow-pssfp-elevated hover:shadow-pssfp-floating',
    haloBg: 'radial-gradient(circle, rgba(255, 255, 255, 0.30) 0%, transparent 70%)',
    iconBg: 'bg-white/15 text-white backdrop-blur-2xl',
    titleColor: 'text-white',
    bodyColor: 'text-white/85',
    ctaColor: 'text-white',
    ctaArrowBg: 'bg-white/15 text-white group-hover:bg-white group-hover:text-[#0E4D3F]',
    focusRing: 'focus-visible:ring-[#C9A227]',
  },
  ink: {
    card: 'bg-gradient-ink-deep text-white border-transparent shadow-pssfp-elevated hover:shadow-pssfp-floating',
    haloBg: 'radial-gradient(circle, rgba(176, 132, 232, 0.40) 0%, transparent 70%)',
    iconBg: 'bg-white/10 text-[#E8C868] backdrop-blur-2xl',
    titleColor: 'text-white',
    bodyColor: 'text-white/85',
    ctaColor: 'text-[#E8C868]',
    ctaArrowBg: 'bg-white/10 text-[#E8C868] group-hover:bg-[#E8C868] group-hover:text-[#14101A]',
    focusRing: 'focus-visible:ring-[#E8C868]',
  },
  gold: {
    card: 'bg-gradient-or-soft text-[#14101A] border-transparent shadow-pssfp-glow-or hover:shadow-pssfp-floating',
    haloBg: 'radial-gradient(circle, rgba(20, 16, 26, 0.20) 0%, transparent 70%)',
    iconBg: 'bg-[#14101A]/10 text-[#14101A] backdrop-blur-2xl',
    titleColor: 'text-[#14101A]',
    bodyColor: 'text-[#14101A]/80',
    ctaColor: 'text-[#14101A]',
    ctaArrowBg: 'bg-[#14101A]/10 text-[#14101A] group-hover:bg-[#14101A] group-hover:text-[#C9A227]',
    focusRing: 'focus-visible:ring-[#0E4D3F]',
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
      className={`group relative flex h-full min-h-[260px] flex-col justify-between overflow-hidden rounded-pssfp-card border p-7 transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 ${v.card} ${v.focusRing}`}
    >
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-16 -top-16 h-48 w-48 rounded-full opacity-0 blur-2xl transition-opacity duration-500 group-hover:opacity-60"
        style={{ background: v.haloBg }}
      />

      <div className="relative">
        <span
          aria-hidden="true"
          className={`inline-flex h-14 w-14 items-center justify-center rounded-2xl transition-transform duration-300 ease-pssfp-out-expo group-hover:scale-110 ${v.iconBg}`}
        >
          <Icon size={28} />
        </span>
        <h3 className={`mt-6 font-heading text-pssfp-h3 font-bold leading-tight ${v.titleColor}`}>
          {title}
        </h3>
        <p className={`mt-3 text-sm leading-relaxed ${v.bodyColor}`}>{body}</p>
      </div>

      <div className={`relative mt-6 inline-flex items-center justify-between gap-3 ${v.ctaColor}`}>
        <span className="inline-flex items-center gap-2 text-sm font-semibold">
          {openLabel}
          {card.external ? <ExternalLink size={14} aria-hidden="true" /> : null}
        </span>
        <span
          aria-hidden="true"
          className={`flex h-10 w-10 items-center justify-center rounded-full transition-all duration-300 ease-pssfp-out-expo group-hover:scale-110 ${v.ctaArrowBg}`}
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
