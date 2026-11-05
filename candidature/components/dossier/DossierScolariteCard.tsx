import { useLocale, useTranslations } from 'next-intl';
import { FileDown } from 'lucide-react';
import type { MaScolarite, ScolaritePalier } from '@/lib/api/client';
import { formatDateLong } from '@/lib/format/date';
import { formatFcfa } from '@/lib/format/montant';

interface DossierScolariteCardProps {
  scolarite: MaScolarite;
}

function Palier({ palier }: { palier: ScolaritePalier }): JSX.Element {
  const t = useTranslations('dossier.scolarite');
  const locale = useLocale();
  const part = palier.du === 0 ? 100 : Math.round((palier.paye / palier.du) * 100);
  const echeance = palier.echeance_texte ?? (palier.echeance ? formatDateLong(palier.echeance, locale) : null);

  return (
    <li data-testid={`palier-${palier.cle}`} className="py-4 first:pt-0 last:pb-0">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h3 className="font-semibold text-[#1A1A1A]">{t(`paliers.${palier.cle}`)}</h3>
        <p className="text-sm tabular-nums text-[#3C3C3C]">
          {t('paye', { paye: formatFcfa(palier.paye, locale), du: formatFcfa(palier.du, locale) })}
        </p>
      </div>
      {/* Barre décorative : les montants exacts sont donnés en texte juste au-dessus. */}
      <div aria-hidden="true" className="mt-2 h-2 overflow-hidden rounded-full bg-[#F4EFFA]">
        <div
          className={`h-full rounded-full ${palier.solde ? 'bg-emerald-600' : palier.en_retard ? 'bg-amber-600' : 'bg-[#4A2E67]'}`}
          style={{ width: `${part}%` }}
        />
      </div>
      <p className="mt-2 flex flex-wrap gap-x-3 text-sm">
        {palier.solde ? (
          <span className="font-semibold text-emerald-800">{t('solde')}</span>
        ) : (
          <span className="text-[#3C3C3C]">
            {t.rich('resteMontant', {
              montant: formatFcfa(palier.reste, locale),
              strong: (chunks) => <strong className="tabular-nums">{chunks}</strong>,
            })}
          </span>
        )}
        {palier.en_retard && <span className="font-semibold text-amber-800">{t('enRetard')}</span>}
        {echeance && !palier.solde && <span className="text-[#5C5C5C]">{t('echeance', { date: echeance })}</span>}
      </p>
    </li>
  );
}

/**
 * Droits d'inscription et tranches de l'auditeur : cumul, paliers, versements
 * enregistrés par la scolarité, relevé PDF. Distinct des frais de dossier.
 */
export function DossierScolariteCard({ scolarite }: DossierScolariteCardProps): JSX.Element {
  const t = useTranslations('dossier.scolarite');
  const locale = useLocale();

  return (
    <section
      aria-labelledby="scolarite-heading"
      data-testid="dossier-scolarite"
      className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7"
    >
      <p className="pssfp-eyebrow">{t('eyebrow')}</p>
      <h2 id="scolarite-heading" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">
        {t('title')}
      </h2>

      <dl className="mt-6 grid gap-4 sm:grid-cols-3">
        <div className="rounded-pssfp-card bg-[#FAF7F2] p-4">
          <dt className="font-ui text-xs uppercase tracking-[0.14em] text-[#5C5C5C]">{t('verse')}</dt>
          <dd className="mt-1 text-xl font-bold tabular-nums text-[#4A2E67]">{formatFcfa(scolarite.verse, locale)}</dd>
        </div>
        <div className="rounded-pssfp-card bg-[#FAF7F2] p-4">
          <dt className="font-ui text-xs uppercase tracking-[0.14em] text-[#5C5C5C]">{t('reste')}</dt>
          <dd className="mt-1 text-xl font-bold tabular-nums text-[#0F3A4A]">{formatFcfa(scolarite.reste ?? 0, locale)}</dd>
        </div>
        <div className="rounded-pssfp-card bg-[#FAF7F2] p-4">
          <dt className="font-ui text-xs uppercase tracking-[0.14em] text-[#5C5C5C]">{t('du')}</dt>
          <dd className="mt-1 text-xl font-bold tabular-nums text-[#1A1A1A]">{formatFcfa(scolarite.du_total ?? 0, locale)}</dd>
        </div>
      </dl>

      {scolarite.paliers.length > 0 && (
        <ul className="mt-6 divide-y divide-[#F4EFFA]">
          {scolarite.paliers.map((p) => (
            <Palier key={p.cle} palier={p} />
          ))}
        </ul>
      )}

      {(scolarite.remise ?? 0) > 0 && (
        <p className="mt-4 text-sm text-[#3C3C3C]">{t('remise', { montant: formatFcfa(scolarite.remise ?? 0, locale) })}</p>
      )}
      {scolarite.trop_percu > 0 && (
        <p className="mt-2 text-sm font-semibold text-amber-800">{t('tropPercu', { montant: formatFcfa(scolarite.trop_percu, locale) })}</p>
      )}

      <details className="mt-6 rounded-pssfp-card border border-[#F4EFFA] px-4 py-1">
        <summary className="flex min-h-11 cursor-pointer items-center font-semibold text-[#4A2E67] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2">
          {t('versementsTitre', { count: scolarite.versements.length })}
        </summary>
        {scolarite.versements.length === 0 ? (
          <p className="mt-3 text-sm text-[#5C5C5C]">{t('aucunVersement')}</p>
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">{t('versementsTitre', { count: scolarite.versements.length })}</caption>
              <thead>
                <tr className="text-xs uppercase tracking-wide text-[#5C5C5C]">
                  <th scope="col" className="py-2 pr-4">{t('colDate')}</th>
                  <th scope="col" className="py-2 pr-4 text-right">{t('colMontant')}</th>
                  <th scope="col" className="py-2 pr-4">{t('colMode')}</th>
                  <th scope="col" className="py-2">{t('colReference')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#F4EFFA]">
                {scolarite.versements.map((v) => (
                  <tr key={`${v.mode}-${v.reference}`}>
                    <th scope="row" className="py-2 pr-4 font-normal">{formatDateLong(v.date, locale)}</th>
                    <td className="py-2 pr-4 text-right tabular-nums">{formatFcfa(v.montant, locale)}</td>
                    <td className="py-2 pr-4">{t.has(`modes.${v.mode}`) ? t(`modes.${v.mode}`) : t('modes.autre')}</td>
                    <td className="py-2 font-mono">{v.reference}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </details>

      <a
        href="/dossier/releve"
        data-testid="releve-download"
        className="mt-6 inline-flex min-h-11 items-center gap-2 rounded-pssfp-button border border-[#4A2E67] px-5 py-2.5 text-sm font-semibold text-[#4A2E67] transition-colors duration-200 hover:bg-[#4A2E67] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
      >
        <FileDown size={16} aria-hidden="true" />
        {t('releve')}
      </a>

      <p className="mt-6 text-xs leading-relaxed text-[#5C5C5C]">{t('note')} {t('fraisDossier')}</p>
    </section>
  );
}
