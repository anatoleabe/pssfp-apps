import { useLocale, useTranslations } from 'next-intl';
import { ArrowUpRight, Award, CalendarClock, Hourglass, ListChecks, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import type { MyCandidature } from '@/lib/api/client';
import type { DossierPhase } from '@/lib/dossier/phase';
import { formatDateLong } from '@/lib/format/date';

const MAIN_SITE_URL = process.env.NEXT_PUBLIC_MAIN_SITE_URL ?? 'https://pssfp.org';

interface DossierDecisionCardProps {
  candidature: MyCandidature;
  phase: DossierPhase;
}

/**
 * Annonce en tête d'espace ce que le candidat vient chercher une fois la
 * campagne close : sa décision, et ce qu'il doit faire ensuite.
 */
export function DossierDecisionCard({ candidature, phase }: DossierDecisionCardProps): JSX.Element | null {
  switch (phase) {
    case 'retenu':
      return <Retenu candidature={candidature} />;
    case 'non_retenu':
      return <NonRetenu candidature={candidature} />;
    case 'en_examen':
      return <EnExamen candidature={candidature} />;
    case 'non_soumis':
      return <NonSoumis />;
    default:
      return null;
  }
}

/** Un paragraphe par ligne : les consignes sont saisies en texte simple dans l'admin. */
function Paragraphes({ texte }: { texte: string }): JSX.Element {
  return (
    <>
      {texte
        .split(/\n+/)
        .map((ligne) => ligne.trim())
        .filter((ligne) => ligne !== '')
        .map((ligne, i) => (
          <p key={i}>{ligne}</p>
        ))}
    </>
  );
}

function Fait({ label, children }: { label: string; children: ReactNode }): JSX.Element {
  return (
    <div className="px-6 py-4 md:px-10">
      <dt className="font-ui text-xs uppercase tracking-[0.14em] text-[#5C5C5C]">{label}</dt>
      <dd className="mt-1 text-base font-semibold text-[#1A1A1A]">{children}</dd>
    </div>
  );
}

function Retenu({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const t = useTranslations('dossier.decision');
  const locale = useLocale();
  const decision = candidature.decision?.resultat === 'retenu' ? candidature.decision : null;
  const consignes = decision?.consignes?.trim() || t('nextStepsDefault');

  return (
    <section
      aria-labelledby="decision-heading"
      data-testid="dossier-decision"
      data-resultat="retenu"
      className="relative overflow-hidden rounded-pssfp-card border border-[#D4AF6A]/60 bg-[#FAF7F2] shadow-pssfp-elevated"
    >
      <div aria-hidden="true" className="h-1.5 bg-[#D4AF6A]" />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-16 -top-10 h-56 w-56 rounded-full bg-[#D4AF6A]/25 blur-3xl"
      />

      <div className="relative flex flex-col-reverse gap-6 p-6 md:flex-row md:items-start md:justify-between md:p-10">
        <div className="max-w-2xl">
          <p className="pssfp-eyebrow">
            {t('eyebrow')}
            {candidature.campagne?.nom ? ` · ${candidature.campagne.nom}` : ''}
          </p>
          <h2
            id="decision-heading"
            className="mt-3 font-heading text-3xl font-bold leading-tight text-[#4A2E67] md:text-[2.6rem]"
          >
            {t('retenuTitle')}
          </h2>
          <p className="mt-4 text-base leading-relaxed text-[#3C3C3C] md:text-lg">{t('retenuLead')}</p>
        </div>
        <span
          aria-hidden="true"
          className="inline-flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-[#4A2E67] text-[#D4AF6A] shadow-pssfp-elevated ring-4 ring-[#D4AF6A]/40 md:h-24 md:w-24"
        >
          <Award size={40} strokeWidth={1.6} />
        </span>
      </div>

      <dl className="relative grid divide-y divide-[#D4AF6A]/25 border-y border-[#D4AF6A]/25 bg-white/60 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
        {candidature.specialite && <Fait label={t('specialite')}>{candidature.specialite}</Fait>}
        <Fait label={t('numero')}>
          <span className="font-mono">{candidature.numero_dossier}</span>
        </Fait>
        {candidature.decided_at && (
          <Fait label={t('decidedOn')}>{formatDateLong(candidature.decided_at, locale)}</Fait>
        )}
      </dl>

      <div className="relative grid gap-6 p-6 md:p-10">
        <div className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft">
          <h3 className="flex items-center gap-3 font-heading text-xl font-bold text-[#1A1A1A]">
            <span
              aria-hidden="true"
              className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-[#F4EFFA] text-[#4A2E67]"
            >
              <ListChecks size={18} />
            </span>
            {t('nextStepsTitle')}
          </h3>
          <div className="mt-4 space-y-3 text-[15px] leading-relaxed text-[#3C3C3C]" data-testid="decision-consignes">
            <Paragraphes texte={consignes} />
          </div>
        </div>

        {decision?.commentaire && (
          <figure className="border-l-4 border-[#D4AF6A] pl-5">
            <figcaption className="font-ui text-xs uppercase tracking-[0.14em] text-[#5C5C5C]">
              {t('commentTitle')}
            </figcaption>
            <blockquote className="mt-2 font-heading text-xl italic leading-snug text-[#3A2452]">
              {decision.commentaire}
            </blockquote>
          </figure>
        )}
      </div>
    </section>
  );
}

function NonRetenu({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const t = useTranslations('dossier.decision');
  const locale = useLocale();
  const motif = candidature.decision?.resultat === 'non_retenu' ? candidature.decision.motif : null;

  return (
    <section
      aria-labelledby="decision-heading"
      data-testid="dossier-decision"
      data-resultat="non_retenu"
      className="relative overflow-hidden rounded-pssfp-card border border-[#A592BD]/50 bg-white shadow-pssfp-soft"
    >
      <div aria-hidden="true" className="h-1.5 bg-[#A592BD]" />
      <div className="p-6 md:p-10">
        <p className="pssfp-eyebrow">
          {t('eyebrow')}
          {candidature.campagne?.nom ? ` · ${candidature.campagne.nom}` : ''}
        </p>
        <h2 id="decision-heading" className="mt-3 font-heading text-3xl font-bold leading-tight text-[#1A1A1A]">
          {t('nonRetenuTitle')}
        </h2>
        <p className="mt-4 max-w-2xl text-base leading-relaxed text-[#3C3C3C]">{t('nonRetenuLead')}</p>

        <p className="mt-4 text-sm text-[#5C5C5C]">
          {t('numero')} <span className="font-mono font-semibold text-[#1A1A1A]">{candidature.numero_dossier}</span>
          {candidature.decided_at && (
            <>
              {' · '}
              {t('decidedOn')} {formatDateLong(candidature.decided_at, locale)}
            </>
          )}
        </p>

        {motif && (
          <div className="mt-6 rounded-pssfp-card bg-[#F4EFFA] p-5">
            <p className="font-ui text-xs uppercase tracking-[0.14em] text-[#4A2E67]">{t('motifTitle')}</p>
            <blockquote className="mt-2 space-y-2 leading-relaxed text-[#3C3C3C]" data-testid="decision-motif">
              <Paragraphes texte={motif} />
            </blockquote>
          </div>
        )}

        <p className="mt-6 max-w-2xl leading-relaxed text-[#3C3C3C]">{t('encouragement')}</p>
        <a
          href={`${MAIN_SITE_URL}/formations`}
          className="mt-5 inline-flex min-h-11 items-center gap-2 rounded-pssfp-button border border-[#4A2E67] px-5 py-2.5 text-sm font-semibold text-[#4A2E67] transition-colors duration-200 hover:bg-[#4A2E67] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
        >
          {t('nonRetenuCta')}
          <ArrowUpRight size={16} aria-hidden="true" />
        </a>
      </div>
    </section>
  );
}

function EnExamen({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const t = useTranslations('dossier.decision');
  const locale = useLocale();
  const resultsAt = candidature.campagne?.results_at ?? null;

  return (
    <section
      aria-labelledby="decision-heading"
      data-testid="dossier-decision"
      data-resultat="en_examen"
      className="flex flex-col gap-5 rounded-pssfp-card bg-[#0F3A4A] p-6 text-white shadow-pssfp-elevated sm:flex-row sm:items-start md:p-8"
    >
      <span
        aria-hidden="true"
        className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/10 text-[#D4AF6A]"
      >
        <Hourglass size={24} />
      </span>
      <div>
        <h2 id="decision-heading" className="font-heading text-2xl font-bold">
          {t('enExamenTitle')}
        </h2>
        <p className="mt-2 max-w-2xl leading-relaxed text-white/90">{t('enExamenLead')}</p>
        {resultsAt && (
          <p className="mt-4 inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-1.5 text-sm font-semibold">
            <CalendarClock size={16} aria-hidden="true" className="text-[#D4AF6A]" />
            {t('resultsOn', { date: formatDateLong(resultsAt, locale) })}
          </p>
        )}
      </div>
    </section>
  );
}

function NonSoumis(): JSX.Element {
  const t = useTranslations('dossier.decision');

  return (
    <section
      aria-labelledby="decision-heading"
      data-testid="dossier-decision"
      data-resultat="non_soumis"
      className="flex flex-col gap-5 rounded-pssfp-card border border-[#E5E0EC] bg-white p-6 shadow-pssfp-soft sm:flex-row sm:items-start md:p-8"
    >
      <span
        aria-hidden="true"
        className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#F4EFFA] text-[#4A2E67]"
      >
        <Lock size={22} />
      </span>
      <div>
        <h2 id="decision-heading" className="font-heading text-2xl font-bold text-[#1A1A1A]">
          {t('nonSoumisTitle')}
        </h2>
        <p className="mt-2 max-w-2xl leading-relaxed text-[#3C3C3C]">{t('nonSoumisLead')}</p>
      </div>
    </section>
  );
}
