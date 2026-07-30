import { getTranslations } from 'next-intl/server';
import { headers } from 'next/headers';
import Link from 'next/link';
import { ArrowRight, ArrowUpRight, CalendarClock, CheckCircle2, Download, FileCheck2, Laptop, MapPin } from 'lucide-react';
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
  // Liste reprise mot pour mot du communiqué conjoint : `paper` marque les
  // pièces qui n'ont de sens qu'en version papier (fiche imprimée, enveloppe).
  const piecesDossier = t.raw('dossier.items') as ReadonlyArray<{ text: string; paper: boolean }>;
  const closingDate = campagne?.closes_at
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeZone: 'Africa/Douala' }).format(new Date(campagne.closes_at))
    : '18 septembre 2026';
  const promoNumero = campagne?.promotion_numero ?? 14;
  const heroTitle = campagne?.nom ?? t('title');
  // Le middleware a validé le token Sanctum et pose cet en-tête ; le hero
  // s'adapte pour un candidat déjà connecté (cohérence avec la nav « Mon dossier »).
  const isLoggedIn = (await headers()).get('x-candidat-session-valid') === '1';

  return (
    <div className="mx-auto max-w-5xl px-6 py-16">
      <section
        aria-labelledby="hero-heading"
        className="relative overflow-hidden rounded-2xl border border-[var(--pssfp-border)] bg-[var(--pssfp-ivoire)] px-6 py-10 shadow-pssfp-soft sm:px-10 sm:py-12"
      >
        {/* Filet prune éditorial (aplat, sans dégradé — charte ADR-0008). */}
        <span aria-hidden="true" className="absolute inset-y-0 left-0 w-1.5 bg-[#4A2E67]" />

        <div className="space-y-6">
          <p className="inline-flex items-center gap-2 rounded-full border border-[#4A2E67]/15 bg-[var(--pssfp-surface)] px-3.5 py-1.5 font-ui text-xs font-semibold uppercase tracking-[0.16em] text-[#4A2E67]">
            <span aria-hidden="true" className="inline-block h-1.5 w-1.5 rounded-full bg-[#D4AF6A]" />
            Promotion {promoNumero} · {t('eyebrow')}
          </p>

          <h1
            id="hero-heading"
            className="font-heading text-4xl font-bold leading-[1.05] tracking-tight text-[#4A2E67] sm:text-5xl md:text-6xl"
          >
            {heroTitle}
          </h1>

          {isOpen && (
            <div className="flex flex-wrap items-center gap-2.5">
              <span className="inline-flex items-center gap-2 rounded-md border border-[#4A2E67]/20 bg-[#F4EFFA] px-3 py-1.5 text-sm font-semibold text-[#4A2E67]">
                <span aria-hidden="true" className="relative inline-flex h-2 w-2">
                  <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75 motion-safe:animate-ping" />
                  <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-600" />
                </span>
                {t('statusOpen')}
              </span>
              {campagne?.closes_at && (
                <CountdownToClose closesAt={campagne.closes_at} ariaLabel={t('countdownAria')} />
              )}
              <span className="inline-flex items-center gap-1.5 rounded-md border border-[#E7D3A0] bg-[#FFF6E0] px-3 py-1.5 text-sm font-semibold text-[#765315]">
                <CalendarClock size={15} aria-hidden="true" />
                {t('closingLabel')} {closingDate}
              </span>
            </div>
          )}

          <p className="max-w-2xl text-lg leading-relaxed text-[#3C3C3C]">
            {isOpen ? (isLoggedIn ? t('introLoggedIn') : t('introOpen')) : t('introClosed')}
          </p>

          {isOpen ? (
            isLoggedIn ? (
              <div className="flex flex-wrap gap-3 pt-1">
                <Link
                  href="/dossier"
                  data-testid="cta-dossier"
                  className="group inline-flex items-center gap-2 rounded-pssfp-button bg-[#4A2E67] px-6 py-3 font-medium text-white shadow-pssfp-elevated transition-all duration-200 ease-pssfp-out-expo hover:-translate-y-0.5 hover:bg-[#3A2452] hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
                >
                  <FileCheck2 size={18} aria-hidden="true" />
                  {t('ctaDossier')}
                  <ArrowRight size={16} aria-hidden="true" className="transition-transform duration-200 group-hover:translate-x-0.5" />
                </Link>
              </div>
            ) : (
              <div className="flex flex-wrap gap-3 pt-1">
                <Link
                  href="/inscription"
                  data-testid="cta-inscription"
                  className="group inline-flex items-center gap-2 rounded-pssfp-button bg-[#4A2E67] px-6 py-3 font-medium text-white shadow-pssfp-elevated transition-all duration-200 ease-pssfp-out-expo hover:-translate-y-0.5 hover:bg-[#3A2452] hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
                >
                  {t('ctaCreateAccount')}
                  <ArrowRight size={16} aria-hidden="true" className="transition-transform duration-200 group-hover:translate-x-0.5" />
                </Link>
                <Link
                  href="/login"
                  data-testid="cta-login"
                  className="inline-flex items-center gap-2 rounded-pssfp-button border border-[#4A2E67] bg-white px-6 py-3 font-medium text-[#4A2E67] transition-all duration-200 hover:bg-[#F4EFFA] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
                >
                  {t('ctaLogin')}
                </Link>
              </div>
            )
          ) : (
            <div
              role="status"
              className="max-w-2xl rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
            >
              {t('campaignClosedNotice')}
            </div>
          )}
        </div>
      </section>

      {campagne?.communique_url && (
        <section
          aria-labelledby="communique-heading"
          className="mt-12 overflow-hidden rounded-2xl border border-[#0F3A4A]/20 bg-[#0F3A4A] text-white shadow-pssfp-soft"
        >
          <div className="flex flex-col gap-6 p-6 sm:p-8 md:flex-row md:items-center md:justify-between">
            <div className="max-w-2xl">
              <p className="inline-flex items-center gap-2 font-ui text-xs font-semibold uppercase tracking-[0.16em] text-[#D4AF6A]">
                <span aria-hidden="true" className="inline-block h-1.5 w-1.5 rounded-full bg-[#D4AF6A]" />
                {t('communique.eyebrow')}
              </p>
              <h2 id="communique-heading" className="mt-3 font-heading text-2xl font-bold leading-snug text-white">
                {t('communique.heading')}
              </h2>
              <p className="mt-3 text-sm leading-relaxed text-white/80">{t('communique.body')}</p>
              {(campagne.communique_reference || campagne.communique_signed_at) && (
                <dl className="mt-4 flex flex-wrap gap-x-8 gap-y-2 text-xs text-white/70">
                  {campagne.communique_reference && (
                    <div>
                      <dt className="font-ui uppercase tracking-[0.12em] text-white/50">
                        {t('communique.referenceLabel')}
                      </dt>
                      <dd className="mt-0.5 font-medium text-white/90">{campagne.communique_reference}</dd>
                    </div>
                  )}
                  {campagne.communique_signed_at && (
                    <div>
                      <dt className="font-ui uppercase tracking-[0.12em] text-white/50">
                        {t('communique.signedAtLabel')}
                      </dt>
                      <dd className="mt-0.5 font-medium text-white/90">
                        {new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeZone: 'Africa/Douala' })
                          .format(new Date(campagne.communique_signed_at))}
                      </dd>
                    </div>
                  )}
                </dl>
              )}
            </div>

            <a
              href={campagne.communique_url}
              target="_blank"
              rel="noopener noreferrer"
              data-testid="communique-download"
              className="group inline-flex shrink-0 items-center gap-2.5 rounded-pssfp-button bg-[#D4AF6A] px-6 py-3.5 font-medium text-[#2A1D0A] shadow-pssfp-elevated transition-all duration-200 ease-pssfp-out-expo hover:-translate-y-0.5 hover:bg-[#E0BF80] hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#0F3A4A]"
            >
              <Download size={18} aria-hidden="true" />
              <span>
                {t('communique.download')}
                <span className="block font-ui text-xs font-normal text-[#2A1D0A]/70">
                  {t('communique.downloadHint')}
                </span>
              </span>
            </a>
          </div>
        </section>
      )}

      <section aria-labelledby="documents-heading" className="mt-12 rounded-lg border border-[#D4AF6A]/40 bg-[#FFFBEA] p-6">
        <div className="flex items-start gap-3">
          <FileCheck2 aria-hidden="true" className="mt-0.5 shrink-0 text-[#8A641D]" />
          <div>
            <h2 id="documents-heading" className="font-heading text-xl font-bold text-[#4A2E67]">
              {t('dossier.heading')}
            </h2>
            <p className="mt-1 text-sm text-[#4B4B4B]">{t('dossier.intro', { date: closingDate })}</p>
            <p className="mt-3 rounded-md border-l-4 border-[#8A641D] bg-white/70 py-2 pl-3 pr-2 text-sm font-medium text-[#5C4310]">
              {t('dossier.warning')}
            </p>
            <ol className="mt-4 list-decimal space-y-2.5 pl-5 text-sm leading-relaxed text-[#333333]">
              {piecesDossier.map((piece) => (
                <li key={piece.text}>
                  {piece.text}
                  {piece.paper && (
                    <span className="ml-2 inline-block whitespace-nowrap rounded-full border border-[#8A641D]/30 bg-white px-2 py-0.5 font-ui text-[11px] font-semibold uppercase tracking-wide text-[#8A641D]">
                      {t('dossier.paperBadge')}
                    </span>
                  )}
                </li>
              ))}
            </ol>
            <p className="mt-4 text-sm text-[#4B4B4B]">{t('dossier.photoNotice')}</p>
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
