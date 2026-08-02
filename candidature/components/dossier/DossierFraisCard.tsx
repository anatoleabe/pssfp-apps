import { CheckCircle2, Banknote, Building2 } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { MyCandidature } from '@/lib/api/client';
import { formatDateFr } from '@/lib/format/date';

const MODE_LABELS: Record<string, string> = {
  cremincam_agence: 'Agence CREMINCAM',
  virement: 'Virement bancaire',
  especes: 'Espèces',
  orange_money: 'Orange Money',
  mtn_money: 'MTN Mobile Money',
  carte_visa: 'Carte Visa / Mastercard',
  autre: 'Autre',
};

export function DossierFraisCard({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const tfr = useTranslations('dossier.frais');
  const isPaid = candidature.frais_paye === true;
  const numero = candidature.numero_dossier;

  return (
    <section
      aria-labelledby="frais-heading"
      className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="pssfp-eyebrow">{tfr('eyebrow')}</p>
          <h2
            id="frais-heading"
            className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]"
          >
            {tfr('title')}
          </h2>
        </div>
        <span className="inline-flex items-center gap-1.5 rounded-full bg-[#FAF7FF] px-3 py-1.5 text-sm font-semibold text-[#4A2E67]">
          50 000 FCFA
        </span>
      </div>

      {isPaid ? (
        <div
          role="status"
          data-testid="dossier-frais-payes"
          className="mt-6 rounded-pssfp-card border border-emerald-200 bg-emerald-50/80 p-5"
        >
          <p className="flex items-center gap-2 font-semibold text-emerald-900">
            <CheckCircle2 size={18} aria-hidden="true" className="text-emerald-600" />
            {tfr('paid')}
          </p>
          <dl className="mt-4 grid gap-3 text-sm text-emerald-900 md:grid-cols-3">
            <div>
              <dt className="text-xs uppercase tracking-wider text-emerald-700">{tfr('mode')}</dt>
              <dd className="mt-1 font-medium">
                {MODE_LABELS[candidature.mode_paiement ?? ''] ?? candidature.mode_paiement ?? '—'}
              </dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wider text-emerald-700">{tfr('reference')}</dt>
              <dd className="mt-1 font-mono">{candidature.reference_paiement ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wider text-emerald-700">{tfr('date')}</dt>
              <dd className="mt-1 font-medium">
                {candidature.date_paiement ? formatDateFr(candidature.date_paiement) : '—'}
              </dd>
            </div>
          </dl>
        </div>
      ) : (
        <div className="mt-6 space-y-5">
          <p className="text-sm leading-relaxed text-[#555]">
            Le paiement n&apos;est requis qu&apos;après la soumission en ligne. Il est nécessaire
            pour valider l&apos;examen administratif de votre dossier.
          </p>

          {/* Bloc CREMINCAM avec illustration banque */}
          <div className="relative overflow-hidden rounded-pssfp-card border border-[#F4EFFA] bg-[#FAF7F2] p-5">
            {/* Illustration banque décorative */}
            <div
              aria-hidden="true"
              className="pointer-events-none absolute -right-4 -top-4 opacity-15"
            >
              <Building2 size={120} className="text-[#4A2E67]" />
            </div>

            <div className="relative">
              <div className="flex items-center gap-3">
                <span
                  aria-hidden="true"
                  className="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-[#4A2E67] text-white shadow-pssfp-glow-prune"
                >
                  <Banknote size={22} />
                </span>
                <div>
                  <p className="font-heading font-bold text-[#4A2E67]">CREMINCAM</p>
                  {/* Acronyme développé dès la première occurrence (audit A-28) :
                      il n'était explicité que plus bas, dans la reprise du communiqué. */}
                  <p className="text-xs text-[#666]">{tfr('cremincamFull')}</p>
                </div>
              </div>
              <ol className="mt-4 list-decimal space-y-2 pl-6 text-sm text-[#333]">
                <li>{tfr('step1')}</li>
                <li>
                  Présenter le numéro de dossier{' '}
                  <span className="rounded-pssfp-button bg-white px-2 py-0.5 font-mono text-[#4A2E67] shadow-pssfp-soft">
                    {numero}
                  </span>
                  .
                </li>
                <li>{tfr('step3')}</li>
                <li>{tfr('step4')}</li>
              </ol>
            </div>
          </div>

          {/* Le bloc « Bientôt disponible — paiement en ligne » (Orange Money,
              MTN Mobile Money, Visa/Mastercard) est retiré pour la campagne P14
              (audit A-28) : annoncer des moyens de paiement à venir sans date
              ferme conduit des candidats à différer leur passage en agence, au
              risque de dépasser la clôture du 18 septembre. À réintroduire le
              jour où ces canaux ouvrent réellement. */}
        </div>
      )}
    </section>
  );
}
