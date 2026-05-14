import Link from 'next/link';
import { useTranslations } from 'next-intl';
import { PssfpLogo } from '@pssfp/ui';
import { Mail, Phone, MapPin, Facebook, Linkedin, Youtube, Twitter } from 'lucide-react';

/**
 * Footer institutionnel — Sprint S5.1 (charte 2026 ADR-0008).
 *
 * Évolutions vs PR Q :
 *   - Séparateur gradient prune→or→prune avant la baseline.
 *   - 4 réseaux sociaux (Facebook, LinkedIn, YouTube, Twitter/X) en cercles or.
 *   - Tagline officielle « Former. Moderniser. Transformer les finances publiques. »
 *   - Tokens charte 2026 (prune/or/graphite) au lieu des anciens hex.
 */

const SOCIAL_LINKS: ReadonlyArray<{
  href: string;
  label: string;
  Icon: typeof Facebook;
}> = [
  { href: 'https://facebook.com/pssfpcameroun', label: 'Facebook PSSFP', Icon: Facebook },
  { href: 'https://www.linkedin.com/company/pssfp', label: 'LinkedIn PSSFP', Icon: Linkedin },
  { href: 'https://youtube.com/@pssfp', label: 'YouTube PSSFP', Icon: Youtube },
  { href: 'https://twitter.com/pssfp_cm', label: 'Twitter / X PSSFP', Icon: Twitter },
];

export function SiteFooter(): JSX.Element {
  const t = useTranslations('footer');
  const tNav = useTranslations('nav');
  const year = new Date().getFullYear();

  return (
    <footer
      data-testid="site-footer"
      className="mt-20 border-t border-[var(--pssfp-border)] bg-gradient-to-b from-white to-[var(--pssfp-bg-subtle)] dark:from-[#14091F] dark:to-[#1A1A1A]"
    >
      {/* Tagline + séparateur gradient prune→or→prune */}
      <div className="mx-auto max-w-7xl px-6 pt-12">
        <p className="font-heading text-pssfp-h3 italic font-medium text-pssfp-prune dark:text-[#B084E8]">
          « Former. Moderniser. Transformer les finances publiques. »
        </p>
        <div aria-hidden="true" className="pssfp-hairline-prune-or mt-6" />
      </div>

      <div className="mx-auto grid max-w-7xl gap-10 px-6 py-12 md:grid-cols-2 lg:grid-cols-4">
        <section aria-labelledby="footer-about-heading">
          <PssfpLogo size={48} />
          <h2 id="footer-about-heading" className="mt-3 font-heading text-base font-bold text-pssfp-prune dark:text-[#B084E8]">
            {t('aboutTitle')}
          </h2>
          <p className="mt-3 text-sm leading-relaxed text-pssfp-graphite-light dark:text-[#B5A8C8]">{t('aboutBody')}</p>
        </section>

        <section aria-labelledby="footer-nav-heading">
          <h2 id="footer-nav-heading" className="font-heading text-base font-bold text-pssfp-prune dark:text-[#B084E8]">
            {t('navTitle')}
          </h2>
          <ul className="mt-3 space-y-2 text-sm">
            <li><Link href="/" className="inline-block py-1 text-pssfp-graphite hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:text-[#F5EFE3] dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]">{tNav('home')}</Link></li>
            <li><Link href="/a-propos" className="inline-block py-1 text-pssfp-graphite hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:text-[#F5EFE3] dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]">{tNav('apropos')}</Link></li>
            <li><Link href="/formations" className="inline-block py-1 text-pssfp-graphite hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:text-[#F5EFE3] dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]">{tNav('formations')}</Link></li>
            <li><Link href="/vie-academique" className="inline-block py-1 text-pssfp-graphite hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:text-[#F5EFE3] dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]">{tNav('vie')}</Link></li>
            <li><Link href="/actualites" className="inline-block py-1 text-pssfp-graphite hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:text-[#F5EFE3] dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]">{tNav('actualites')}</Link></li>
            <li><Link href="/contact" className="inline-block py-1 text-pssfp-graphite hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:text-[#F5EFE3] dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]">{tNav('contact')}</Link></li>
          </ul>
        </section>

        <section aria-labelledby="footer-services-heading">
          <h2 id="footer-services-heading" className="font-heading text-base font-bold text-pssfp-prune dark:text-[#B084E8]">
            {t('servicesTitle')}
          </h2>
          <ul className="mt-3 space-y-2 text-sm">
            <li>
              <a
                href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
                className="inline-block py-1 text-pssfp-graphite hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:text-[#F5EFE3] dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]"
              >
                {t('servicesCandidature')}
              </a>
            </li>
            <li>
              <a
                href={process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#'}
                className="inline-block py-1 text-pssfp-graphite hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:text-[#F5EFE3] dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]"
              >
                {t('servicesLibrary')}
              </a>
            </li>
            <li>
              <a
                href={process.env.NEXT_PUBLIC_FOAD_URL ?? 'https://foad.pssfp.net'}
                rel="noopener noreferrer"
                target="_blank"
                className="inline-block py-1 text-pssfp-graphite hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:text-[#F5EFE3] dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]"
              >
                {t('servicesFoad')}
              </a>
            </li>
          </ul>
        </section>

        <section aria-labelledby="footer-contact-heading">
          <h2 id="footer-contact-heading" className="font-heading text-base font-bold text-pssfp-prune dark:text-[#B084E8]">
            {t('contactTitle')}
          </h2>
          <ul className="mt-3 space-y-2 text-sm text-pssfp-graphite dark:text-[#F5EFE3]">
            <li className="flex items-start gap-2">
              <MapPin size={14} className="mt-1 shrink-0 text-pssfp-prune dark:text-[#B084E8]" aria-hidden="true" />
              <span>{t('contactAddress')}</span>
            </li>
            <li className="flex items-start gap-2">
              <Phone size={14} className="mt-1 shrink-0 text-pssfp-prune dark:text-[#B084E8]" aria-hidden="true" />
              <a href="tel:+237222234567" className="inline-block py-1 hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]">
                +237 222 23 45 67
              </a>
            </li>
            <li className="flex items-start gap-2">
              <Mail size={14} className="mt-1 shrink-0 text-pssfp-prune dark:text-[#B084E8]" aria-hidden="true" />
              <a href="mailto:contact@pssfp.net" className="inline-block py-1 hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]">
                contact@pssfp.net
              </a>
            </li>
          </ul>

          {/* Réseaux sociaux — cercles or, sobres */}
          <ul aria-label="Réseaux sociaux PSSFP" className="mt-5 flex items-center gap-2.5">
            {SOCIAL_LINKS.map((s) => (
              <li key={s.label}>
                <a
                  href={s.href}
                  rel="noopener noreferrer"
                  target="_blank"
                  aria-label={s.label}
                  className="group inline-flex h-9 w-9 items-center justify-center rounded-full border border-pssfp-or/40 bg-white text-pssfp-prune transition-all duration-200 hover:border-pssfp-or hover:bg-pssfp-or hover:text-pssfp-graphite focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2 dark:bg-[#1F0E2E] dark:text-[#B084E8] dark:hover:bg-pssfp-or dark:hover:text-[#14091F]"
                >
                  <s.Icon size={16} strokeWidth={1.75} aria-hidden="true" />
                </a>
              </li>
            ))}
          </ul>
        </section>
      </div>

      <div className="border-t border-[var(--pssfp-border)] dark:border-[#3A2A55]">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-6 py-4 text-xs text-pssfp-graphite-light dark:text-[#B5A8C8]">
          <p>
            © {year} {t('copyright')}
          </p>
          <ul className="flex flex-wrap gap-4">
            <li>
              <Link href="/mentions-legales" className="inline-block py-1 hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]">
                {t('legal')}
              </Link>
            </li>
            <li>
              <Link href="/confidentialite" className="inline-block py-1 hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]">
                {t('privacy')}
              </Link>
            </li>
            <li>
              <Link href="/plan-du-site" className="inline-block py-1 hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 rounded dark:hover:text-[#B084E8] dark:focus-visible:ring-[#E5C788]">
                {t('sitemap')}
              </Link>
            </li>
          </ul>
        </div>
      </div>
    </footer>
  );
}
