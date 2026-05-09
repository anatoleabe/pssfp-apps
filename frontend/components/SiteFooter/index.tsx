import Link from 'next/link';
import { useTranslations } from 'next-intl';
import { PssfpLogo } from '@pssfp/ui';
import { Mail, Phone, MapPin } from 'lucide-react';

export function SiteFooter(): JSX.Element {
  const t = useTranslations('footer');
  const tNav = useTranslations('nav');
  const year = new Date().getFullYear();

  return (
    <footer
      data-testid="site-footer"
      className="mt-20 border-t border-[#EDE7F6] bg-gradient-to-b from-white to-[#FAF7FF]"
    >
      <div className="mx-auto grid max-w-7xl gap-10 px-6 py-12 md:grid-cols-2 lg:grid-cols-4">
        <section aria-labelledby="footer-about-heading">
          <PssfpLogo size={48} />
          <h2 id="footer-about-heading" className="mt-3 font-heading text-base font-bold text-[#6B2FA0]">
            {t('aboutTitle')}
          </h2>
          <p className="mt-3 text-sm text-[#555]">{t('aboutBody')}</p>
        </section>

        <section aria-labelledby="footer-nav-heading">
          <h2 id="footer-nav-heading" className="font-heading text-base font-bold text-[#6B2FA0]">
            {t('navTitle')}
          </h2>
          <ul className="mt-3 space-y-2 text-sm">
            <li><Link href="/" className="inline-block py-1 text-[#333] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded">{tNav('home')}</Link></li>
            <li><Link href="/pssfp" className="inline-block py-1 text-[#333] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded">{tNav('pssfp')}</Link></li>
            <li><Link href="/formations" className="inline-block py-1 text-[#333] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded">{tNav('formations')}</Link></li>
            <li><Link href="/vie-academique" className="inline-block py-1 text-[#333] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded">{tNav('vie')}</Link></li>
            <li><Link href="/actualites" className="inline-block py-1 text-[#333] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded">{tNav('actualites')}</Link></li>
            <li><Link href="/contact" className="inline-block py-1 text-[#333] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded">{tNav('contact')}</Link></li>
          </ul>
        </section>

        <section aria-labelledby="footer-services-heading">
          <h2 id="footer-services-heading" className="font-heading text-base font-bold text-[#6B2FA0]">
            {t('servicesTitle')}
          </h2>
          <ul className="mt-3 space-y-2 text-sm">
            <li>
              <a
                href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
                className="inline-block py-1 text-[#333] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded"
              >
                {t('servicesCandidature')}
              </a>
            </li>
            <li>
              <a
                href={process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#'}
                className="inline-block py-1 text-[#333] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded"
              >
                {t('servicesLibrary')}
              </a>
            </li>
            <li>
              <a
                href={process.env.NEXT_PUBLIC_FOAD_URL ?? 'https://foad.pssfp.net'}
                rel="noopener noreferrer"
                target="_blank"
                className="inline-block py-1 text-[#333] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded"
              >
                {t('servicesFoad')}
              </a>
            </li>
          </ul>
        </section>

        <section aria-labelledby="footer-contact-heading">
          <h2 id="footer-contact-heading" className="font-heading text-base font-bold text-[#6B2FA0]">
            {t('contactTitle')}
          </h2>
          <ul className="mt-3 space-y-2 text-sm text-[#333]">
            <li className="flex items-start gap-2">
              <MapPin size={14} className="mt-1 shrink-0 text-[#6B2FA0]" aria-hidden="true" />
              <span>{t('contactAddress')}</span>
            </li>
            <li className="flex items-start gap-2">
              <Phone size={14} className="mt-1 shrink-0 text-[#6B2FA0]" aria-hidden="true" />
              <a href="tel:+237222234567" className="inline-block py-1 hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded">
                +237 222 23 45 67
              </a>
            </li>
            <li className="flex items-start gap-2">
              <Mail size={14} className="mt-1 shrink-0 text-[#6B2FA0]" aria-hidden="true" />
              <a href="mailto:contact@pssfp.net" className="inline-block py-1 hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded">
                contact@pssfp.net
              </a>
            </li>
          </ul>
        </section>
      </div>

      <div className="border-t border-[#EDE7F6]">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-6 py-4 text-xs text-[#555]">
          <p>
            © {year} {t('copyright')}
          </p>
          <ul className="flex flex-wrap gap-4">
            <li>
              <Link href="/mentions-legales" className="inline-block py-1 hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded">
                {t('legal')}
              </Link>
            </li>
            <li>
              <Link href="/confidentialite" className="inline-block py-1 hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded">
                {t('privacy')}
              </Link>
            </li>
            <li>
              <Link href="/plan-du-site" className="inline-block py-1 hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 rounded">
                {t('sitemap')}
              </Link>
            </li>
          </ul>
        </div>
      </div>
    </footer>
  );
}
