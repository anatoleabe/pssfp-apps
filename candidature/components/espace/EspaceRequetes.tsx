import { useTranslations } from 'next-intl';
import { Download } from 'lucide-react';
import type { MesRequetes } from '@/lib/api/client';
import { FormulaireRequete } from './FormulaireRequete';

const CARTE = 'min-w-0 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7';

/** « Mes requêtes » : dépôt, puis suivi étape par étape (D27). */
export function EspaceRequetes({ donnees, consultation, message }: { donnees: MesRequetes; consultation: boolean; message: 'annulee' | 'erreur' | null }): JSX.Element {
  const t = useTranslations('espace.requetes');

  return (
    <div className="grid min-w-0 gap-6" data-testid="espace-requetes">
      {message && (
        <p role={message === 'erreur' ? 'alert' : 'status'} className="rounded-pssfp-button border border-[#D4AF6A] bg-[#FAF7F2] px-4 py-3 text-sm text-[#3C3C3C]">{t(message)}</p>
      )}
      {!consultation && (
        <section aria-labelledby="nouvelle-requete" className={CARTE}>
          <p className="pssfp-eyebrow">{t('eyebrow')}</p>
          <h2 id="nouvelle-requete" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">{t('nouvelle')}</h2>
          <p className="mt-2 text-sm text-[#3C3C3C]">{t('aide')}</p>
          <div className="mt-5">
            <FormulaireRequete options={donnees} />
          </div>
        </section>
      )}

      <section aria-labelledby="suivi-requetes" className={CARTE}>
        <h2 id="suivi-requetes" className="font-heading text-xl font-bold text-[#4A2E67]">{t('suivi')}</h2>
        {donnees.requetes.length === 0 ? (
          <p className="mt-3 text-[#3C3C3C]">{t('aucune')}</p>
        ) : (
          <ul className="mt-4 grid gap-4">
            {donnees.requetes.map((r) => (
              <li key={r.id} className="rounded-pssfp-button border border-[#F4EFFA] p-4" data-testid="requete">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <p className="font-semibold text-[#1A1A1A]">{r.objet}</p>
                  <span className="rounded-full bg-[#F4EFFA] px-3 py-1 text-xs font-semibold text-[#4A2E67]">{r.statut_libelle}</span>
                </div>
                <p className="mt-1 font-mono text-xs text-[#5C3A7E]">{r.numero}</p>
                {r.note_retenue !== null && <p className="mt-2 text-sm text-[#1A1A1A]">{t('noteCorrigee', { note: r.note_retenue.toLocaleString('fr-FR', { minimumFractionDigits: 2 }) })}</p>}
                {r.motif_decision && <p className="mt-2 text-sm text-[#3C3C3C]">{t('decision', { motif: r.motif_decision })}</p>}
                <ol className="mt-3 border-l-2 border-[#F4EFFA] pl-4 text-sm text-[#3C3C3C]">
                  {r.etapes.map((e, i) => (
                    <li key={`${e.statut}-${i}`} className="py-1">
                      <time dateTime={e.le ?? undefined} className="mr-2 text-xs text-[#5C5C5C]">{e.le ? new Date(e.le).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : ''}</time>
                      {e.libelle}
                    </li>
                  ))}
                </ol>
                <div className="mt-3 flex flex-wrap gap-3">
                  {r.document_disponible && (
                    <a href={`/dossier/requetes/${r.id}/document`} className="inline-flex min-h-11 items-center gap-2 font-semibold text-[#4A2E67] underline-offset-4 hover:underline">
                      <Download size={16} aria-hidden="true" />
                      {t('telecharger')}
                    </a>
                  )}
                  {r.annulable && !consultation && (
                    <form method="post" action={`/dossier/requetes/${r.id}/annuler`}>
                      <button type="submit" className="min-h-11 text-sm font-semibold text-[#8A1C1C] underline-offset-4 hover:underline">{t('annuler')}</button>
                    </form>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
