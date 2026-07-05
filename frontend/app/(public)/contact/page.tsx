import { Mail, Phone, MapPin, Clock, ChevronDown } from 'lucide-react';
import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { ContactForm } from '@/components/ContactForm';
import { GoogleMapEmbed } from '@/components/GoogleMapEmbed';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('contact');
  return {
    title: t('metaTitle'),
    description: t('metaDescription'),
  };
}

interface ContactInfo {
  key: string;
  icon: typeof MapPin;
  label: string;
  primary: string;
  secondary: string;
  href?: string;
}

const FAQ_KEYS = ['horaires', 'admissions', 'visite', 'departement'] as const;

export default async function ContactPage(): Promise<JSX.Element> {
  const t = await getTranslations('contact');

  const contactInfos: ContactInfo[] = [
    {
      key: 'adresse',
      icon: MapPin,
      label: t('infos.adresse.label'),
      primary: t('infos.adresse.primary'),
      secondary: t('infos.adresse.secondary'),
    },
    {
      key: 'telephone',
      icon: Phone,
      label: t('infos.telephone.label'),
      primary: t('infos.telephone.primary'),
      secondary: t('infos.telephone.secondary'),
      href: 'tel:+237222234567',
    },
    {
      key: 'email',
      icon: Mail,
      label: t('infos.email.label'),
      primary: t('infos.email.primary'),
      secondary: t('infos.email.secondary'),
      href: 'mailto:contact@pssfp.org',
    },
  ];

  return (
    <>
      {/* ──────────────────────────────────────────────────────────────────
          Hero éditorial — eyebrow + headline Playfair + paragraphe + badge 48h
         ────────────────────────────────────────────────────────────────── */}
      <section
        aria-labelledby="contact-heading"
        className="relative isolate overflow-hidden bg-gradient-lavande-blanc dark:bg-[#14091F] dark:bg-none"
      >
        {/* Grain dotted décoratif (aria-hidden) */}
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-0 -z-10 opacity-[0.025]"
          style={{
            backgroundImage:
              'radial-gradient(circle at 1px 1px, #4A2E67 1px, transparent 0)',
            backgroundSize: '24px 24px',
          }}
        />
        {/* Halos colorés (aria-hidden) */}
        <div
          aria-hidden="true"
          className="pointer-events-none absolute -top-32 -right-32 -z-10 h-[480px] w-[480px] rounded-full bg-[#4A2E67] opacity-[0.06] blur-3xl"
        />
        <div
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-40 -left-32 -z-10 h-[420px] w-[420px] rounded-full bg-[#D4AF6A] opacity-[0.08] blur-3xl"
        />

        <div className="mx-auto max-w-4xl px-6 py-20 text-center md:py-28">
          <p className="pssfp-eyebrow">{t('eyebrow')}</p>

          <h1
            id="contact-heading"
            className="mt-5 font-heading font-bold text-pssfp-h1 text-[#1A1A1A] dark:text-[#FAF7F2]"
          >
            {t('heroTitlePre')}{' '}
            <span className="relative inline-block">
              <span className="pssfp-text-gradient-violet-or">{t('heroTitleHighlight')}</span>
              <svg
                aria-hidden="true"
                className="absolute -bottom-2 left-0 w-full"
                height="10"
                viewBox="0 0 200 10"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
              >
                <path
                  d="M2 8C50 3 150 3 198 8"
                  stroke="#D4AF6A"
                  strokeWidth="3"
                  strokeLinecap="round"
                />
              </svg>
            </span>
          </h1>

          <p className="mx-auto mt-6 max-w-2xl pssfp-lead">{t('heroLead')}</p>

          <div className="mt-8 flex items-center justify-center">
            <span className="inline-flex items-center gap-2 rounded-full border border-[#4A2E67]/20 bg-[#F4EFFA] px-4 py-1.5 text-sm font-medium text-[#4A2E67] dark:border-[#5C3A7E]/40 dark:bg-[#2A1E3A] dark:text-[#F4EFFA]">
              <Clock size={14} aria-hidden="true" />
              {t('responseBadge')}
            </span>
          </div>
        </div>
      </section>

      {/* ──────────────────────────────────────────────────────────────────
          Split — formulaire (gauche) + coordonnées cartes (droite)
         ────────────────────────────────────────────────────────────────── */}
      <section className="mx-auto max-w-7xl px-6 py-16 md:py-20">
        <div className="grid gap-12 lg:grid-cols-12 lg:gap-16">
          {/* Formulaire */}
          <div className="lg:col-span-7">
            <header className="mb-8">
              <p className="pssfp-eyebrow">{t('writeEyebrow')}</p>
              <h2 className="mt-3 font-heading text-pssfp-h2 font-bold text-[#1A1A1A] dark:text-[#FAF7F2]">
                {t('writeTitle')}
              </h2>
              <p className="mt-3 pssfp-body text-[#555] dark:text-[#C9C2D8]">{t('writeHelp')}</p>
            </header>

            <div className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft dark:border-[#2A1E3A] dark:bg-[#1A1428] md:p-8">
              <ContactForm />
            </div>
          </div>

          {/* Coordonnées */}
          <aside aria-labelledby="info-heading" className="lg:col-span-5">
            <header className="mb-8">
              <p className="pssfp-eyebrow">{t('infoEyebrow')}</p>
              <h2
                id="info-heading"
                className="mt-3 font-heading text-pssfp-h2 font-bold text-[#1A1A1A] dark:text-[#FAF7F2]"
              >
                {t('infoTitle')}
              </h2>
              <p className="mt-3 pssfp-body text-[#555] dark:text-[#C9C2D8]">{t('infoHelp')}</p>
            </header>

            <ul className="space-y-4">
              {contactInfos.map((info) => (
                <li key={info.key}>
                  <ContactCard info={info} />
                </li>
              ))}
            </ul>

            {/* Séparateur ornement */}
            <div className="mt-10 flex items-center gap-4" aria-hidden="true">
              <div className="h-px flex-1 bg-gradient-to-r from-transparent to-[#D4AF6A]/40" />
              <span className="text-[#D4AF6A]">✦</span>
              <div className="h-px flex-1 bg-gradient-to-l from-transparent to-[#D4AF6A]/40" />
            </div>
          </aside>
        </div>
      </section>

      {/* ──────────────────────────────────────────────────────────────────
          Carte d'accès — pleine largeur
         ────────────────────────────────────────────────────────────────── */}
      <section
        aria-labelledby="map-heading"
        className="bg-gradient-lavande-blanc py-16 dark:bg-[#14091F] dark:bg-none md:py-20"
      >
        <div className="mx-auto max-w-7xl px-6">
          <header className="mx-auto mb-10 max-w-2xl text-center">
            <p className="pssfp-eyebrow">{t('mapEyebrow')}</p>
            <h2
              id="map-heading"
              className="mt-3 font-heading text-pssfp-h2 font-bold text-[#1A1A1A] dark:text-[#FAF7F2]"
            >
              {t('mapTitle')}
            </h2>
            <p className="mt-3 pssfp-body text-[#555] dark:text-[#C9C2D8]">{t('mapHelp')}</p>
          </header>

          <div className="overflow-hidden rounded-pssfp-card border border-[#F4EFFA] bg-white shadow-pssfp-elevated dark:border-[#2A1E3A] dark:bg-[#1A1428]">
            <GoogleMapEmbed />
          </div>
        </div>
      </section>

      {/* ──────────────────────────────────────────────────────────────────
          FAQ — <details>/<summary> (server-component-safe, sans JS)
         ────────────────────────────────────────────────────────────────── */}
      <section
        aria-labelledby="faq-heading"
        className="mx-auto max-w-4xl px-6 py-16 md:py-20"
      >
        <header className="mb-10 text-center">
          <p className="pssfp-eyebrow inline-flex items-center justify-center gap-2">
            <span className="h-1.5 w-1.5 rounded-full bg-[#4A2E67]" aria-hidden="true" />
            {t('faqEyebrow')}
            <span className="h-1.5 w-1.5 rounded-full bg-[#D4AF6A]" aria-hidden="true" />
          </p>
          <h2
            id="faq-heading"
            className="mt-3 font-heading text-pssfp-h2 font-bold text-[#1A1A1A] dark:text-[#FAF7F2]"
          >
            {t('faqTitle')}
          </h2>
          <p className="mt-3 pssfp-body text-[#555] dark:text-[#C9C2D8]">{t('faqHelp')}</p>
        </header>

        <ul className="space-y-3">
          {FAQ_KEYS.map((key, i) => (
            <li key={key}>
              <details
                className="group rounded-pssfp-card border border-[#F4EFFA] bg-white px-5 py-1 shadow-pssfp-soft transition-shadow open:shadow-pssfp-elevated dark:border-[#2A1E3A] dark:bg-[#1A1428]"
                {...(i === 0 ? { open: true } : {})}
              >
                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 py-4 font-heading text-base font-semibold text-[#1A1A1A] outline-none transition-colors hover:text-[#4A2E67] focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 dark:text-[#FAF7F2] dark:hover:text-[#D4AF6A]">
                  <span>{t(`faq.${key}.q`)}</span>
                  <ChevronDown
                    size={18}
                    aria-hidden="true"
                    className="shrink-0 text-[#4A2E67] transition-transform duration-200 group-open:rotate-180 dark:text-[#D4AF6A]"
                  />
                </summary>
                <div className="pb-5 pr-8 text-sm leading-relaxed text-[#555] dark:text-[#C9C2D8]">
                  {t(`faq.${key}.a`)}
                </div>
              </details>
            </li>
          ))}
        </ul>

        {/* Ornement de clôture */}
        <div className="mt-12 flex items-center justify-center gap-4" aria-hidden="true">
          <div className="h-px w-20 bg-gradient-to-r from-transparent to-[#4A2E67]/40" />
          <div className="h-2 w-2 rounded-full bg-[#D4AF6A]" />
          <div className="h-px w-20 bg-gradient-to-l from-transparent to-[#4A2E67]/40" />
        </div>
      </section>
    </>
  );
}

function ContactCard({ info }: { info: ContactInfo }): JSX.Element {
  const Icon = info.icon;
  const inner = (
    <div className="group relative flex items-start gap-4 rounded-pssfp-card border border-[#F4EFFA] bg-white p-5 shadow-pssfp-soft transition-all duration-200 hover:-translate-y-0.5 hover:border-[#4A2E67]/30 hover:shadow-pssfp-elevated dark:border-[#2A1E3A] dark:bg-[#1A1428] dark:hover:border-[#5C3A7E]/40">
      <span
        aria-hidden="true"
        className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-prune-or text-white shadow-pssfp-glow-prune transition-transform duration-200 group-hover:scale-105"
      >
        <Icon size={20} />
      </span>
      <div className="min-w-0">
        <p className="font-heading text-sm font-semibold uppercase tracking-wider text-[#4A2E67] dark:text-[#D4AF6A]">
          {info.label}
        </p>
        <p className="mt-1 break-words font-heading text-base font-semibold text-[#1A1A1A] dark:text-[#FAF7F2]">
          {info.primary}
        </p>
        <p className="mt-0.5 text-sm text-[#555] dark:text-[#C9C2D8]">{info.secondary}</p>
      </div>
    </div>
  );

  return info.href ? (
    <a
      href={info.href}
      className="block rounded-pssfp-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
    >
      {inner}
    </a>
  ) : (
    inner
  );
}
