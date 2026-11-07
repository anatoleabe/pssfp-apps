import { useTranslations } from 'next-intl';
import type { CalendrierAuditeur } from '@/lib/api/client';

const CARTE = 'min-w-0 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7';

function jour(iso: string): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}

/**
 * Calendrier publié de l'année (lot 12a) : événements du niveau de l'auditeur et événements communs, par mois ;
 * communiqués signés diffusés par la scolarité (lot 12b).
 */
export function CalendrierAnnee({ calendrier, erreurCommunique = false }: { calendrier: CalendrierAuditeur; erreurCommunique?: boolean }): JSX.Element {
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
      {(calendrier.communiques.length > 0 || erreurCommunique) && (
        <div className="mt-6 min-w-0 border-t border-[#F4EFFA] pt-5" data-testid="parcours-communiques">
          <h4 className="text-sm font-semibold uppercase tracking-wide text-[#5C3A7E]">{t('communiquesTitre')}</h4>
          {erreurCommunique && (
            <p role="alert" className="mt-2 rounded-md bg-[#FDF2F2] px-3 py-2 text-sm text-[#8C1D18]">{t('erreurCommunique')}</p>
          )}
          <ul className="mt-2 grid gap-2">
            {calendrier.communiques.map((c) => (
              <li key={c.uuid} className="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md bg-[#FAF7F2] px-3 py-2 text-sm">
                <span className="min-w-0 flex-1 basis-56">
                  <span className="block font-semibold text-[#1A1A1A]">{c.objet}</span>
                  <span className="block text-[#5C5C5C]">{t('communique', { numero: c.numero, date: c.date_signature ? jour(c.date_signature) : '' })}</span>
                </span>
                <a
                  href={`/dossier/parcours/communiques/${c.uuid}`}
                  className="inline-flex min-h-[44px] shrink-0 items-center rounded-md border border-[#4A2E67] px-4 font-semibold text-[#4A2E67] hover:bg-[#4A2E67] hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#4A2E67]"
                  aria-label={t('telechargerLibelle', { objet: c.objet })}
                >
                  {t('telecharger')}
                </a>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}
