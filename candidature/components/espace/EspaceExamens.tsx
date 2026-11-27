import { useTranslations } from 'next-intl';
import type { ExamensAuditeur } from '@/lib/api/client';

const CARTE = 'min-w-0 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7';

function jour(iso: string): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

/** « Mes examens » de l'auditeur (lot 27) : épreuves des plannings publiés où il est convoqué, avec sa salle. */
export function EspaceExamens({ examens }: { examens: ExamensAuditeur }): JSX.Element | null {
  const t = useTranslations('espace.examens');
  if (examens.epreuves.length === 0) {
    return null;
  }

  return (
    <section aria-labelledby="examens-titre" className={`${CARTE} mb-6`} data-testid="espace-examens">
      <p className="pssfp-eyebrow">{t('eyebrow')}</p>
      <h2 id="examens-titre" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">{t('titre')}</h2>
      <p className="mt-2 text-sm text-[#3C3C3C]">{t('intro')}</p>
      {!examens.autorise && (
        <p role="alert" className="mt-4 rounded-md border border-[#E8C9A0] bg-[#FAF7F2] p-3 text-sm font-semibold text-[#8A5A00]">{t('nonAutorise')}</p>
      )}
      <ol className="mt-5 grid gap-4">
        {examens.epreuves.map((e) => (
          <li key={e.id} className="min-w-0 border-l-2 border-[#4A2E67] pl-3 text-sm text-[#1A1A1A]">
            <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
              <span className="font-semibold first-letter:uppercase"><time dateTime={e.date}>{jour(e.date)}</time></span>
              <span className="font-semibold tabular-nums"><time>{e.debut}</time> – <time>{e.fin}</time></span>
              {e.rattrapage && <span className="rounded-full bg-[#FAF7F2] px-2 py-0.5 text-xs font-semibold text-[#8A5A00]">{t('rattrapage')}</span>}
            </p>
            <p className="mt-1 min-w-0 font-semibold">{e.ecue}{e.intitule ? ` — ${e.intitule}` : ''}</p>
            <p className="mt-1 text-[#3C3C3C]">{[e.session, e.salle ? t('salle', { salle: e.salle }) : t('salleAVenir')].join(' · ')}</p>
          </li>
        ))}
      </ol>
    </section>
  );
}
