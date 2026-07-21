import { getTranslations } from 'next-intl/server';
import Link from 'next/link';
import { ArrowUpRight, CheckCircle2, FileCheck2, Laptop, MapPin } from 'lucide-react';
import { CountdownToClose } from '@/components/CountdownToClose';
import { getCurrentCampaign, getSpecialites } from '@/lib/api/client';
import { FALLBACK_SPECIALITES } from '@/lib/api/fallbacks';

const MAIN_SITE_URL = process.env.NEXT_PUBLIC_MAIN_SITE_URL ?? 'https://pssfp.org';

export default async function HomePage(): Promise<JSX.Element> {
  const t = await getTranslations('home');
  const [campaignResult, specialitesResult] = await Promise.all([
    getCurrentCampaign(),
    getSpecialites(),
  ]);
  const campagne = campaignResult.ok ? campaignResult.data : null;
  const isOpen = campagne?.is_currently_open === true;
  const specialites = specialitesResult.ok && specialitesResult.data.length > 0
    ? specialitesResult.data
    : [...FALLBACK_SPECIALITES];
  const conditionsList = t.raw('conditionsList') as string[];
  const filiereTaglines = t.raw('filieres') as Record<string, string>;
  const closingDate = campagne?.closes_at
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeZone: 'Africa/Douala' }).format(new Date(campagne.closes_at))
    : '18 septembre 2026';
  const campaignTitle = campagne
    ? `Promotion ${campagne.promotion_numero} — ${campagne.nom}`
    : t('title');

  return (
    <div className="mx-auto max-w-5xl px-6 py-16">
      <section aria-labelledby="hero-heading" className="space-y-6">
        <div className="flex flex-wrap items-center gap-3">
          <h1
            id="hero-heading"
            className="font-heading text-4xl font-bold text-[#4A2E67] md:text-5xl"
          >
            {campaignTitle}
          </h1>
          {isOpen && campagne?.closes_at && (
            <CountdownToClose closesAt={campagne.closes_at} ariaLabel={t('countdownAria')} />
          )}
        </div>

        <p className="max-w-3xl text-lg text-[#333333]">
          {isOpen ? t('introOpen') : t('introClosed')}
        </p>
        {isOpen && (
          <p className="inline-flex rounded-full bg-[#FFF6E0] px-4 py-2 text-sm font-semibold text-[#765315]">
            Clôture : {closingDate}
          </p>
        )}

        {isOpen ? (
          <div className="flex flex-wrap gap-4">
            <Link
              href="/inscription"
              data-testid="cta-inscription"
              className="rounded-md bg-[#4A2E67] px-6 py-3 font-medium text-white hover:bg-[#5C3A7E]"
            >
              {t('ctaCreateAccount')}
            </Link>
            <Link
              href="/login"
              className="rounded-md border border-[#4A2E67] px-6 py-3 font-medium text-[#4A2E67] hover:bg-[#F4EFFA]"
            >
              {t('ctaLogin')}
            </Link>
          </div>
        ) : (
          <div
            role="status"
            className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
          >
            {t('campaignClosedNotice')}
          </div>
        )}
      </section>

      <section aria-labelledby="documents-heading" className="mt-12 rounded-lg border border-[#D4AF6A]/40 bg-[#FFFBEA] p-6">
        <div className="flex items-start gap-3">
          <FileCheck2 aria-hidden="true" className="mt-0.5 shrink-0 text-[#8A641D]" />
          <div>
            <h2 id="documents-heading" className="font-heading text-xl font-bold text-[#4A2E67]">Pièces à préparer</h2>
            <p className="mt-1 text-sm text-[#4B4B4B]">Préparez des fichiers PDF, JPG ou PNG lisibles avant de commencer.</p>
            <ul className="mt-3 grid gap-x-8 gap-y-1 text-sm text-[#333333] sm:grid-cols-2">
              <li>Photo d&apos;identité récente</li>
              <li>Diplôme ou attestation de réussite</li>
              <li>Acte de naissance et relevés de notes</li>
              <li>CV, lettre de motivation et attestation employeur</li>
            </ul>
          </div>
        </div>
      </section>

      <section aria-labelledby="filieres-heading" className="mt-14">
        <h2
          id="filieres-heading"
          className="font-heading text-2xl font-bold text-[#4A2E67]"
        >
          {t('filieresHeading')}
        </h2>
        <p className="mt-2 max-w-2xl text-sm text-[#666]">{t('filieresIntro')}</p>

        <ol className="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {specialites.map((s, i) => (
            <li
              key={s.slug}
              className="rounded-lg border border-[#F4EFFA] bg-white p-5 shadow-sm"
            >
              <span
                aria-hidden="true"
                className="mb-2 inline-flex h-8 w-8 items-center justify-center rounded-full bg-[#4A2E67] text-sm font-bold text-white"
              >
                {String(i + 1).padStart(2, '0')}
              </span>
              <h3 className="font-heading text-base font-semibold text-[#1A1A1A]">{s.label}</h3>
              {filiereTaglines[s.slug] && (
                <p className="mt-1 text-sm text-[#666]">{filiereTaglines[s.slug]}</p>
              )}
              <details className="mt-4 border-t border-[#E4DCEE] pt-3 text-sm text-[#4B4B4B]">
                <summary className="cursor-pointer font-semibold text-[#4A2E67] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67]">Découvrir la fiche</summary>
                <dl className="mt-3 space-y-2">
                  <div><dt className="font-semibold">Objectif</dt><dd>{filiereTaglines[s.slug] ?? 'Approfondir une spécialité des finances publiques.'}</dd></div>
                  <div><dt className="font-semibold">Profil recommandé</dt><dd>Cadres et professionnels titulaires d&apos;un Bac+3 avec une expérience liée aux finances publiques.</dd></div>
                  <div><dt className="font-semibold">Débouchés</dt><dd>Administrations publiques, collectivités, organismes de contrôle et partenaires du développement.</dd></div>
                  <div><dt className="font-semibold">Mode et places</dt><dd>Présentiel : 25 places · Distanciel : 10 places.</dd></div>
                </dl>
              </details>
            </li>
          ))}
        </ol>
      </section>

      <section aria-labelledby="conditions-heading" className="mt-14 grid gap-4 md:grid-cols-2">
        <div className="rounded-lg border border-[#F4EFFA] bg-white p-6 shadow-sm">
          <h2
            id="conditions-heading"
            className="font-heading text-lg font-bold text-[#4A2E67]"
          >
            {t('conditionsHeading')}
          </h2>
          <ul className="mt-4 space-y-3">
            {conditionsList.map((item) => (
              <li key={item} className="flex items-start gap-2.5 text-sm text-[#333333]">
                <CheckCircle2 size={18} aria-hidden="true" className="mt-0.5 shrink-0 text-[#4A2E67]" />
                <span>{item}</span>
              </li>
            ))}
          </ul>
        </div>

        <div className="rounded-lg border border-[#0F3A4A]/15 bg-[#0F3A4A] p-6 text-white">
          <h2 className="font-heading text-lg font-bold text-white">{t('modalitesHeading')}</h2>
          <ul className="mt-4 space-y-3">
            <li className="flex items-start gap-2.5 text-sm text-white/90">
              <MapPin size={18} aria-hidden="true" className="mt-0.5 shrink-0 text-[#D4AF6A]" />
              <span>{t('modalitesPresentiel')}</span>
            </li>
            <li className="flex items-start gap-2.5 text-sm text-white/90">
              <Laptop size={18} aria-hidden="true" className="mt-0.5 shrink-0 text-[#D4AF6A]" />
              <span>{t('modalitesDistanciel')}</span>
            </li>
          </ul>
        </div>

        <a
          href={`${MAIN_SITE_URL}/formations/admission`}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex w-fit items-center gap-1.5 text-sm font-medium text-[#4A2E67] hover:underline md:col-span-2"
        >
          {t('learnMoreAdmission')}
          <ArrowUpRight size={14} aria-hidden="true" />
        </a>
      </section>

      <section
        aria-labelledby="why-heading"
        className="mt-12 grid gap-4 md:grid-cols-2 lg:grid-cols-4"
      >
        <h2 id="why-heading" className="sr-only">
          {t('whyHeading')}
        </h2>
        <Pillar title={t('pillars.excellence.title')} body={t('pillars.excellence.body')} />
        <Pillar title={t('pillars.partenariats.title')} body={t('pillars.partenariats.body')} />
        <Pillar title={t('pillars.debouches.title')} body={t('pillars.debouches.body')} />
        <Pillar title={t('pillars.foad.title')} body={t('pillars.foad.body')} />
      </section>

      <section className="mt-12 rounded-lg border border-[#D4AF6A]/40 bg-[#FFFBEA] p-6">
        <h2 className="mb-2 font-heading text-lg font-semibold text-[#D4AF6A]">{t('feeTitle')}</h2>
        <p className="text-sm text-[#333333]">{t('feeNotice')}</p>
      </section>
    </div>
  );
}

function Pillar({ title, body }: { title: string; body: string }): JSX.Element {
  return (
    <div className="rounded-lg border border-[#F4EFFA] bg-white p-5 shadow-sm">
      <h3 className="mb-2 font-heading text-base font-semibold text-[#4A2E67]">{title}</h3>
      <p className="text-sm text-[#666]">{body}</p>
    </div>
  );
}
