import { useTranslations } from 'next-intl';
import type { CalendrierAuditeur } from '@/lib/api/client';

const CARTE = 'min-w-0 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7';

function jour(iso: string): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}

/** Calendrier publié de l'année (lot 12a) : événements du niveau de l'auditeur et événements communs, par mois. */
export function CalendrierAnnee({ calendrier }: { calendrier: CalendrierAuditeur }): JSX.Element {
  const t = useTranslations('espace.parcours.calendrier');
  const aujourdhui = new Date().toISOString().slice(0, 10);
  const parMois = new Map<string, CalendrierAuditeur['evenements']>();
  for (const e of calendrier.evenements) {
    const cle = e.debut.slice(0, 7);
    parMois.set(cle, [...(parMois.get(cle) ?? []), e]);
  }

  return (
    <section aria-labelledby="calendrier-heading" className={CARTE} data-testid="parcours-calendrier">
      <h3 id="calendrier-heading" className="font-heading text-xl font-bold text-[#4A2E67]">{t('titre', { annee: calendrier.annee_academique })}</h3>
      <p className="mt-2 text-sm text-[#3C3C3C]">{t('aide')}</p>
      <div className="mt-4 grid gap-5">
        {[...parMois.entries()].map(([mois, evenements]) => (
          <div key={mois} className="min-w-0">
            <h4 className="text-sm font-semibold uppercase tracking-wide text-[#5C3A7E]">
              {new Date(`${mois}-15T12:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })}
            </h4>
            <ol className="mt-2 grid gap-2">
              {evenements.map((e, i) => {
                const passe = e.fin < aujourdhui;
                return (
                  <li key={`${e.debut}-${i}`} className={`flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1 border-l-2 pl-3 text-sm ${passe ? 'border-[#D9D2E3] text-[#5C5C5C]' : 'border-[#4A2E67] text-[#1A1A1A]'}`}>
                    <span className="shrink-0 font-semibold tabular-nums sm:w-36">
                      <time dateTime={e.debut}>{jour(e.debut)}</time>
                      {!e.jalon && <> – <time dateTime={e.fin}>{jour(e.fin)}</time></>}
                    </span>
                    <span className={`min-w-0 flex-1 basis-48 ${e.statut === 'annule' ? 'line-through' : ''}`}>{e.libelle}</span>
                    {(e.statut === 'annule' || e.statut === 'reporte' || e.reporte) && (
                      <span className="rounded-full bg-[#FAF7F2] px-2 py-0.5 text-xs font-semibold text-[#8A5A00]">
                        {e.statut === 'annule' ? e.statut_libelle : t('dateModifiee')}
                      </span>
                    )}
                  </li>
                );
              })}
            </ol>
          </div>
        ))}
      </div>
    </section>
  );
}
