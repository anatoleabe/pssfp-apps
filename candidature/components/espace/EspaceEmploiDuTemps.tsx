import { useTranslations } from 'next-intl';
import { ChevronLeft, ChevronRight, Video } from 'lucide-react';
import type { EmploiDuTempsAuditeur, SeanceAuditeur } from '@/lib/api/client';
import { Link } from '@/navigation';

const CARTE = 'min-w-0 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7';
const BOUTON = 'inline-flex min-h-[44px] items-center gap-1 rounded-md border border-[#4A2E67] px-3 text-sm font-semibold text-[#4A2E67] hover:bg-[#4A2E67] hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#4A2E67]';

function jour(iso: string, options: Intl.DateTimeFormatOptions): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString('fr-FR', options);
}

function decaler(iso: string, jours: number): string {
  const d = new Date(`${iso}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + jours);
  return d.toISOString().slice(0, 10);
}

function Seance({ s }: { s: SeanceAuditeur }): JSX.Element {
  const t = useTranslations('espace.emploi');
  const inactive = s.statut === 'annulee' || s.statut === 'reportee';

  return (
    <li className={`min-w-0 border-l-2 pl-3 text-sm ${inactive ? 'border-[#D9D2E3] text-[#5C5C5C]' : 'border-[#4A2E67] text-[#1A1A1A]'}`}>
      <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span className="font-semibold tabular-nums"><time>{s.debut}</time> – <time>{s.fin}</time></span>
        <span className={`min-w-0 font-semibold ${inactive ? 'line-through' : ''}`}>{s.ecue}{s.intitule ? ` — ${s.intitule}` : ''}</span>
        {s.statut === 'annulee' && <span className="rounded-full bg-[#FAF7F2] px-2 py-0.5 text-xs font-semibold text-[#8A5A00]">{t('annulee')}</span>}
        {s.statut === 'reportee' && (
          <span className="rounded-full bg-[#FAF7F2] px-2 py-0.5 text-xs font-semibold text-[#8A5A00]">
            {s.reportee_au ? t('reportee', { date: jour(s.reportee_au, { weekday: 'long', day: 'numeric', month: 'long' }) }) : t('annulee')}
          </span>
        )}
        {s.statut === 'tenue' && <span className="rounded-full bg-[#EEF7F1] px-2 py-0.5 text-xs font-semibold text-[#1F6B3A]">{t('tenue')}</span>}
      </p>
      <p className="mt-1 text-[#3C3C3C]">
        {[s.nature ? t(`nature.${s.nature}`) : null, t(`modalite.${s.modalite}`), s.enseignant ? t('enseignant', { nom: s.enseignant }) : null, s.salle ? t('salle', { salle: s.salle }) : null]
          .filter(Boolean).join(' · ')}
      </p>
      {s.lien_reunion && (
        <a href={s.lien_reunion} target="_blank" rel="noopener noreferrer" className="mt-1 inline-flex min-h-[44px] items-center gap-1 font-semibold text-[#4A2E67] underline-offset-4 hover:underline"
          aria-label={t('rejoindreLibelle', { ecue: s.ecue ?? '', date: jour(s.date, { day: 'numeric', month: 'long' }) })}>
          <Video aria-hidden="true" className="h-4 w-4" />{t('rejoindre')}
        </a>
      )}
    </li>
  );
}

/** « Emploi du temps » de l'auditeur (lot 12c) : séances publiées de son parcours, semaine par semaine. */
export function EspaceEmploiDuTemps({ edt }: { edt: EmploiDuTempsAuditeur }): JSX.Element {
  const t = useTranslations('espace.emploi');
  const jours = Array.from({ length: 7 }, (_, i) => decaler(edt.semaine, i));
  const parJour = new Map(jours.map((j) => [j, edt.seances.filter((s) => s.date === j)]));
  const affiches = jours.filter((j, i) => i < 6 || (parJour.get(j)?.length ?? 0) > 0);

  return (
    <section aria-labelledby="emploi-titre" className={CARTE} data-testid="espace-emploi">
      <p className="pssfp-eyebrow">{t('eyebrow')}</p>
      <h2 id="emploi-titre" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">
        {t('titre', { debut: jour(edt.semaine, { day: 'numeric', month: 'long' }), fin: jour(decaler(edt.semaine, 6), { day: 'numeric', month: 'long', year: 'numeric' }) })}
      </h2>
      {edt.premiere && edt.derniere ? (
        <p className="mt-2 text-sm text-[#3C3C3C]">{t('periode', { premiere: jour(edt.premiere, { day: 'numeric', month: 'long' }), derniere: jour(edt.derniere, { day: 'numeric', month: 'long', year: 'numeric' }) })}</p>
      ) : (
        <p className="mt-2 text-sm text-[#3C3C3C]">{t('aucunePublication')}</p>
      )}
      <nav aria-label={t('navLabel')} className="mt-4 flex flex-wrap gap-2">
        <Link href={`/dossier/emploi-du-temps?semaine=${edt.precedente}`} className={BOUTON}><ChevronLeft aria-hidden="true" className="h-4 w-4" />{t('precedente')}</Link>
        <Link href={`/dossier/emploi-du-temps?semaine=${edt.suivante}`} className={BOUTON}>{t('suivante')}<ChevronRight aria-hidden="true" className="h-4 w-4" /></Link>
      </nav>
      {edt.seances.length === 0 ? (
        <p className="mt-5 text-sm text-[#3C3C3C]">{t('semaineVide')}</p>
      ) : (
        <div className="mt-5 grid gap-5">
          {affiches.map((j) => (
            <div key={j} className="min-w-0">
              <h3 className="text-sm font-semibold uppercase tracking-wide text-[#5C3A7E]">{jour(j, { weekday: 'long', day: 'numeric', month: 'long' })}</h3>
              {(parJour.get(j) ?? []).length === 0 ? (
                <p className="mt-1 text-sm text-[#5C5C5C]">—</p>
              ) : (
                <ol className="mt-2 grid gap-3">{(parJour.get(j) ?? []).map((s) => <Seance key={s.id} s={s} />)}</ol>
              )}
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
