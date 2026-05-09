import Link from 'next/link';
import { getTranslations } from 'next-intl/server';

export const metadata = {
  title: 'Plan du site',
  description: 'Toutes les pages publiques du site PSSFP.',
};

export default async function PlanDuSitePage(): Promise<JSX.Element> {
  const t = await getTranslations('sitemap');
  const tNav = await getTranslations('nav');

  const sections = [
    {
      key: 'main' as const,
      links: [
        { href: '/', label: tNav('home') },
        { href: '/pssfp', label: tNav('pssfp') },
        { href: '/formations', label: tNav('formations') },
        { href: '/vie-academique', label: tNav('vie') },
        { href: '/actualites', label: tNav('actualites') },
        { href: '/contact', label: tNav('contact') },
      ],
    },
    {
      key: 'transversal' as const,
      links: [
        { href: '/mentions-legales', label: 'Mentions légales' },
        { href: '/confidentialite', label: 'Politique de confidentialité' },
      ],
    },
  ];

  return (
    <article className="mx-auto max-w-3xl px-6 py-12 md:py-16">
      <h1 className="font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl">
        {t('title')}
      </h1>
      <p className="mt-4 text-[#555]">{t('intro')}</p>

      {sections.map((section) => (
        <section key={section.key} className="mt-8">
          <h2 className="font-heading text-xl font-semibold text-[#333]">{t(section.key)}</h2>
          <ul className="mt-3 space-y-2 text-[#333]">
            {section.links.map((link) => (
              <li key={link.href}>
                <Link href={link.href} className="underline hover:text-[#6B2FA0]">
                  {link.label}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ))}

      <section className="mt-8">
        <h2 className="font-heading text-xl font-semibold text-[#333]">{t('external')}</h2>
        <ul className="mt-3 space-y-2 text-[#333]">
          <li>
            <a
              href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
              className="underline hover:text-[#6B2FA0]"
            >
              candidature.pssfp.net
            </a>
          </li>
          <li>
            <a
              href={process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#'}
              className="underline hover:text-[#6B2FA0]"
            >
              bibliotheque.pssfp.net
            </a>
          </li>
          <li>
            <a
              href={process.env.NEXT_PUBLIC_FOAD_URL ?? 'https://foad.pssfp.net'}
              rel="noopener noreferrer"
              target="_blank"
              className="underline hover:text-[#6B2FA0]"
            >
              foad.pssfp.net (FOAD)
            </a>
          </li>
        </ul>
      </section>
    </article>
  );
}
