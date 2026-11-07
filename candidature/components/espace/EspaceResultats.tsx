import { useTranslations } from 'next-intl';
import { Download } from 'lucide-react';
import type { MesResultats, ResultatPublie } from '@/lib/api/client';

const CARTE = 'min-w-0 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7';
const LIEN =
  'inline-flex min-h-11 items-center gap-2 rounded-pssfp-button border border-[#F4EFFA] bg-white px-4 py-2 text-sm font-semibold text-[#333] transition-colors duration-200 hover:border-[#D4AF6A] hover:text-[#4A2E67] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2';

/** Résultats publiés de l'auditeur, avec ses relevés et attestations. Rien n'apparaît avant la publication. */
export function EspaceResultats({ resultats, erreurDocument }: { resultats: MesResultats; erreurDocument: boolean }): JSX.Element {
  const t = useTranslations('espace.resultats');

  return (
    <div className="grid min-w-0 gap-6" data-testid="espace-resultats">
      {erreurDocument && (
        <p role="alert" className="rounded-pssfp-button border border-[#D4AF6A] bg-[#FAF7F2] px-4 py-3 text-sm text-[#3C3C3C]">{t('erreurDocument')}</p>
      )}
      {resultats.resultats.length === 0 ? (
        <section aria-labelledby="resultats-heading" className={CARTE}>
          <p className="pssfp-eyebrow">{t('eyebrow')}</p>
          <h2 id="resultats-heading" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">{t('aucunTitre')}</h2>
          <p className="mt-3 text-[#3C3C3C]" data-testid="resultats-aucun">{t('aucun')}</p>
        </section>
      ) : (
        resultats.resultats.map((r) => <Resultat key={r.deliberation} r={r} />)
      )}
      <section aria-labelledby="certificat-heading" className={CARTE}>
        <h2 id="certificat-heading" className="font-heading text-xl font-bold text-[#4A2E67]">{t('certificatTitre')}</h2>
        {resultats.certificat_scolarite === null ? (
          <a href="/dossier/documents-officiels/certificat_scolarite" className={`${LIEN} mt-4`} data-testid="doc-certificat">
            <Download size={16} aria-hidden="true" />
            {t('certificat')}
          </a>
        ) : (
          <p className="mt-3 text-sm text-[#3C3C3C]">{resultats.certificat_scolarite}</p>
        )}
      </section>
    </div>
  );
}

function Resultat({ r }: { r: ResultatPublie }): JSX.Element {
  const t = useTranslations('espace.resultats');
  const nombre = (v: number | null): string => (v === null ? '—' : v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
  const id = `resultat-${r.deliberation}`;

  return (
    <section aria-labelledby={id} className={CARTE} data-testid="resultat">
      <p className="pssfp-eyebrow">{t('annee', { annee: r.annee_academique, session: t(`session.${r.session}`) })}</p>
      <h2 id={id} className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">{t(`decision.${r.decision}`)}</h2>
      <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div><dt className="text-xs uppercase text-[#5C5C5C]">{t('credits')}</dt><dd className="text-lg font-semibold text-[#1A1A1A]">{r.credits}</dd></div>
        <div><dt className="text-xs uppercase text-[#5C5C5C]">{t('moyenne')}</dt><dd className="text-lg font-semibold text-[#1A1A1A]">{nombre(r.moyenne)}</dd></div>
        <div><dt className="text-xs uppercase text-[#5C5C5C]">{t('mgp')}</dt><dd className="text-lg font-semibold text-[#1A1A1A]">{nombre(r.mgp)}</dd></div>
        <div><dt className="text-xs uppercase text-[#5C5C5C]">{t('mention')}</dt><dd className="text-lg font-semibold text-[#1A1A1A]">{r.mention ?? '—'}</dd></div>
      </dl>
      {r.requetes_jusqu_au && <p className="mt-3 text-sm text-[#3C3C3C]">{t('requetes', { date: new Date(r.requetes_jusqu_au).toLocaleDateString('fr-FR') })}</p>}

      <div className="mt-5 overflow-x-auto">
        <table className="w-full min-w-[36rem] table-fixed text-sm">
          <caption className="sr-only">{t('caption')}</caption>
          <thead>
            <tr className="text-xs uppercase text-[#5C5C5C]">
              <th scope="col" className="w-28 py-2 pr-2 text-left font-semibold">{t('colonnes.code')}</th>
              <th scope="col" className="py-2 pr-2 text-left font-semibold">{t('colonnes.ue')}</th>
              <th scope="col" className="w-16 py-2 pr-2 text-right font-semibold">{t('colonnes.credits')}</th>
              <th scope="col" className="w-16 py-2 pr-2 text-right font-semibold">{t('colonnes.note')}</th>
              <th scope="col" className="w-14 py-2 pr-2 text-left font-semibold">{t('colonnes.grade')}</th>
              <th scope="col" className="w-40 py-2 text-left font-semibold">{t('colonnes.acquisition')}</th>
            </tr>
          </thead>
          <tbody>
            {r.ues.map((ue) => (
              <tr key={ue.code} className="border-t border-[#F4EFFA] text-[#3C3C3C]">
                <td className="py-2 pr-2 font-mono text-xs text-[#5C3A7E]">{ue.code}</td>
                <th scope="row" className="py-2 pr-2 text-left font-normal">{ue.intitule}</th>
                <td className="py-2 pr-2 text-right tabular-nums">{ue.credits}</td>
                <td className="py-2 pr-2 text-right tabular-nums">{nombre(ue.note)}</td>
                <td className="py-2 pr-2">{ue.grade ?? '—'}</td>
                <td className={`py-2 ${ue.mode === 'non_acquise' ? 'text-[#8A1C1C]' : ''}`}>
                  {t(`mode.${ue.mode}`)}{ue.annee_acquisition ? ` (${ue.annee_acquisition})` : ''}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-5 flex flex-wrap gap-3">
        <a href={`/dossier/documents-officiels/releve_notes?deliberation=${r.deliberation}`} className={LIEN} data-testid="doc-releve">
          <Download size={16} aria-hidden="true" />
          {t('releve')}
        </a>
        {r.documents.attestation_reussite === null ? (
          <a href={`/dossier/documents-officiels/attestation_reussite?deliberation=${r.deliberation}`} className={LIEN} data-testid="doc-attestation">
            <Download size={16} aria-hidden="true" />
            {t('attestation')}
          </a>
        ) : (
          r.decision !== 'AJ' && <p className="text-sm text-[#3C3C3C]">{r.documents.attestation_reussite}</p>
        )}
      </div>
    </section>
  );
}
