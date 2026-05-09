import { ExternalLink, BookOpen, GraduationCap, FileSignature } from 'lucide-react';
import { getTranslations } from 'next-intl/server';

interface Card {
  key: 'foad' | 'biblio' | 'candidature';
  href: string;
  external: boolean;
}

export async function HomeAccessRapide(): Promise<JSX.Element> {
  const t = await getTranslations('home.access');

  const cards: ReadonlyArray<Card> = [
    {
      key: 'foad',
      href: process.env.NEXT_PUBLIC_FOAD_URL ?? 'https://foad.pssfp.net',
      external: true,
    },
    {
      key: 'biblio',
      href: process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#',
      external: true,
    },
    {
      key: 'candidature',
      href: process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#',
      external: true,
    },
  ];

  const Icons = {
    foad: GraduationCap,
    biblio: BookOpen,
    candidature: FileSignature,
  } as const;

  return (
    <section
      aria-labelledby="access-heading"
      data-testid="home-access"
      className="bg-white"
    >
      <div className="mx-auto max-w-7xl px-6 py-16 md:py-20">
        <header className="mb-10 max-w-3xl">
          <h2
            id="access-heading"
            className="font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl"
          >
            {t('title')}
          </h2>
          <p className="mt-3 text-[#555]">{t('intro')}</p>
        </header>

        <ul className="grid gap-5 md:grid-cols-3">
          {cards.map((card) => {
            const Icon = Icons[card.key];
            return (
              <li key={card.key}>
                <a
                  href={card.href}
                  data-testid={`access-${card.key}`}
                  rel={card.external ? 'noopener noreferrer' : undefined}
                  target={card.external ? '_blank' : undefined}
                  className="group flex h-full flex-col rounded-xl border border-[#EDE7F6] bg-gradient-to-br from-white to-[#FAF7FF] p-6 transition-all hover:-translate-y-1 hover:border-[#9B59B6] hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
                >
                  <span className="inline-flex h-12 w-12 items-center justify-center rounded-lg bg-[#EDE7F6] text-[#6B2FA0]">
                    <Icon size={24} aria-hidden="true" />
                  </span>
                  <h3 className="mt-4 font-heading text-lg font-bold text-[#333]">
                    {t(`${card.key}Title`)}
                  </h3>
                  <p className="mt-2 grow text-sm text-[#555]">{t(`${card.key}Body`)}</p>
                  <span className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-[#6B2FA0]">
                    {t('open')}
                    {card.external ? <ExternalLink size={14} aria-hidden="true" /> : null}
                  </span>
                </a>
              </li>
            );
          })}
        </ul>
      </div>
    </section>
  );
}
