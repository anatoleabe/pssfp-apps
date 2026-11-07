import { useTranslations } from 'next-intl';
import { Download } from 'lucide-react';
import type { MonMemoire } from '@/lib/api/client';
import { DepotMemoire } from './DepotMemoire';

const CARTE = 'min-w-0 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7';

function date(iso: string | null): string {
  return iso ? new Date(`${iso}T12:00:00`).toLocaleDateString('fr-FR') : '—';
}

/** « Mon mémoire » : étapes, avancement, conditions de l'attestation, dépôts PDF, attestation (lot 11). */
export function EspaceMemoire({ memoire, consultation, erreur }: { memoire: MonMemoire | null; consultation: boolean; erreur: boolean }): JSX.Element {
  const t = useTranslations('espace.memoire');

  if (memoire === null) {
    return (
      <section className={CARTE} data-testid="espace-memoire">
        <h2 className="font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">{t('titre')}</h2>
        <p className="mt-3 text-[#3C3C3C]">{t('aucun')}</p>
      </section>
    );
  }

  return (
    <div className="grid min-w-0 gap-6" data-testid="espace-memoire">
      {erreur && <p role="alert" className="rounded-pssfp-button border border-[#8A1C1C] bg-[#FDF2F2] px-4 py-3 text-sm text-[#8A1C1C]">{t('erreur')}</p>}

      <section aria-labelledby="memoire-titre" className={CARTE}>
        <p className="pssfp-eyebrow">{t('eyebrow')}</p>
        <h2 id="memoire-titre" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">{memoire.theme ?? t('themeAbsent')}</h2>
        <dl className="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <div><dt className="font-semibold text-[#5C5C5C]">{t('encadreurAcademique')}</dt><dd className="text-[#1A1A1A]">{memoire.encadreur_academique ?? '—'}</dd></div>
          <div><dt className="font-semibold text-[#5C5C5C]">{t('encadreurProfessionnel')}</dt><dd className="text-[#1A1A1A]">{memoire.encadreur_professionnel ?? '—'}</dd></div>
          <div><dt className="font-semibold text-[#5C5C5C]">{t('structure')}</dt><dd className="text-[#1A1A1A]">{memoire.structure_accueil ?? '—'}</dd></div>
          <div><dt className="font-semibold text-[#5C5C5C]">{t('etat')}</dt><dd className="text-[#1A1A1A]">{memoire.statut_libelle}{memoire.date_soutenance ? ` — ${t('soutenance', { date: date(memoire.date_soutenance) })}` : ''}</dd></div>
        </dl>
        <div className="mt-5">
          <p className="text-sm font-semibold text-[#1A1A1A]">{t('avancement', { pourcentage: memoire.avancement.pourcentage })}</p>
          <div className="mt-2 h-3 w-full overflow-hidden rounded-full bg-[#F4EFFA]" role="progressbar" aria-label={t('avancementLabel')}
            aria-valuemin={0} aria-valuemax={100} aria-valuenow={memoire.avancement.pourcentage}>
            <div className="h-full bg-[#4A2E67]" style={{ width: `${memoire.avancement.pourcentage}%` }} />
          </div>
          {memoire.avancement.estime !== null && <p className="mt-2 text-sm text-[#3C3C3C]">{t('estime', { pourcentage: memoire.avancement.estime })}</p>}
          {memoire.avancement.prochaine && <p className="mt-1 text-sm text-[#3C3C3C]">{t('prochaine', { etape: memoire.avancement.prochaine })}</p>}
          {memoire.avancement.retards.length > 0 && <p className="mt-1 text-sm font-semibold text-[#8A1C1C]">{t('retards', { etapes: memoire.avancement.retards.join(', ') })}</p>}
        </div>
      </section>

      <section aria-labelledby="memoire-etapes" className={CARTE}>
        <h2 id="memoire-etapes" className="font-heading text-xl font-bold text-[#4A2E67]">{t('etapes')}</h2>
        <ol className="mt-4 grid gap-4">
          {memoire.etapes.map((e) => (
            <li key={e.etape} className="min-w-0 rounded-pssfp-button border border-[#F4EFFA] p-4" data-testid="etape-memoire">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-semibold text-[#1A1A1A]">{e.libelle}</p>
                <span className="rounded-full bg-[#F4EFFA] px-3 py-1 text-xs font-semibold text-[#4A2E67]">{e.statut_libelle}</span>
              </div>
              <p className="mt-1 text-sm text-[#3C3C3C]">
                {t('prevue', { date: date(e.date_prevue) })}
                {e.realisee_le ? ` · ${t('realisee', { date: date(e.realisee_le) })}` : ''}
                {e.note !== null ? ` · ${t('note', { note: e.note.toLocaleString('fr-FR', { minimumFractionDigits: 2 }) })}` : ''}
              </p>
              {e.appreciation && <p className="mt-2 text-sm text-[#3C3C3C]">{t('appreciation', { texte: e.appreciation })}</p>}
              {e.document_depose && <p className="mt-1 text-sm text-[#1F6B3A]">{t('documentDepose')}</p>}
              {e.deposable && !consultation && <DepotMemoire etape={e.etape} />}
            </li>
          ))}
        </ol>
      </section>

      <section aria-labelledby="memoire-attestation" className={CARTE}>
        <h2 id="memoire-attestation" className="font-heading text-xl font-bold text-[#4A2E67]">{t('attestation')}</h2>
        {memoire.attestation ? (
          <div className="mt-3">
            <p className="text-sm text-[#1A1A1A]">{t('attestationDelivree', { numero: memoire.attestation.numero, date: date(memoire.attestation.delivree_le) })}</p>
            <a href="/dossier/memoire/attestation" className="mt-3 inline-flex min-h-11 items-center gap-2 font-semibold text-[#4A2E67] underline-offset-4 hover:underline">
              <Download size={16} aria-hidden="true" />
              {t('telecharger')}
            </a>
          </div>
        ) : (
          <>
            <p className="mt-2 text-sm text-[#3C3C3C]">{t('conditionsAide')}</p>
            <ul className="mt-3 grid gap-2 text-sm">
              {memoire.conditions.map((c) => (
                <li key={c.libelle} className="flex gap-2">
                  <span aria-hidden="true" className={c.ok ? 'font-bold text-[#1F6B3A]' : 'font-bold text-[#8A1C1C]'}>{c.ok ? '✓' : '✗'}</span>
                  <span className="sr-only">{c.ok ? t('remplie') : t('manquante')}</span>
                  <span className="text-[#1A1A1A]">{c.libelle}</span>
                </li>
              ))}
            </ul>
          </>
        )}
      </section>
    </div>
  );
}
