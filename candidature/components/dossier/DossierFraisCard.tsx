import { CheckCircle2, Banknote, Smartphone, CreditCard, Building2 } from 'lucide-react';
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
  const isPaid = candidature.frais_paye === true;
  const numero = candidature.numero_dossier;

  return (
    <section
      aria-labelledby="frais-heading"
      className="rounded-pssfp-card border border-[#EDE7F6] bg-white p-6 shadow-pssfp-soft md:p-7"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="pssfp-eyebrow">Paiement</p>
          <h2
            id="frais-heading"
            className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A0A2E]"
          >
            Frais de candidature
          </h2>
        </div>
        <span className="inline-flex items-center gap-1.5 rounded-full bg-[#FAF7FF] px-3 py-1.5 text-sm font-semibold text-[#6B2FA0]">
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
            Frais réglés
          </p>
          <dl className="mt-4 grid gap-3 text-sm text-emerald-900 md:grid-cols-3">
            <div>
              <dt className="text-xs uppercase tracking-wider text-emerald-700">Mode</dt>
              <dd className="mt-1 font-medium">
                {MODE_LABELS[candidature.mode_paiement ?? ''] ?? candidature.mode_paiement ?? '—'}
              </dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wider text-emerald-700">Référence</dt>
              <dd className="mt-1 font-mono">{candidature.reference_paiement ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wider text-emerald-700">Date</dt>
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
          <div className="relative overflow-hidden rounded-pssfp-card border border-[#EDE7F6] bg-gradient-lavande-blanc p-5">
            {/* Illustration banque décorative */}
            <div
              aria-hidden="true"
              className="pointer-events-none absolute -right-4 -top-4 opacity-15"
            >
              <Building2 size={120} className="text-[#6B2FA0]" />
            </div>

            <div className="relative">
              <div className="flex items-center gap-3">
                <span
                  aria-hidden="true"
                  className="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-violet text-white shadow-pssfp-glow-violet"
                >
                  <Banknote size={22} />
                </span>
                <div>
                  <p className="font-heading font-bold text-[#6B2FA0]">CREMINCAM</p>
                  <p className="text-xs text-[#666]">Mode de paiement actif (V1)</p>
                </div>
              </div>
              <ol className="mt-4 list-decimal space-y-2 pl-6 text-sm text-[#333]">
                <li>Se rendre dans une agence CREMINCAM.</li>
                <li>
                  Présenter le numéro de dossier{' '}
                  <span className="rounded-pssfp-button bg-white px-2 py-0.5 font-mono text-[#6B2FA0] shadow-pssfp-soft">
                    {numero}
                  </span>
                  .
                </li>
                <li>Payer 50 000 FCFA et conserver le récépissé bancaire.</li>
                <li>Apporter le récépissé lors du dépôt physique au PSSFP.</li>
              </ol>
            </div>
          </div>

          {/* Boutons mode paiement à venir (grisés) */}
          <div>
            <p className="mb-3 text-xs uppercase tracking-wider text-[#888]">
              Bientôt disponible — paiement en ligne
            </p>
            <ul className="grid gap-3 sm:grid-cols-3">
              {[
                { Icon: Smartphone, label: 'Orange Money' },
                { Icon: Smartphone, label: 'MTN Mobile Money' },
                { Icon: CreditCard, label: 'Visa / Mastercard' },
              ].map((m) => (
                <li
                  key={m.label}
                  className="relative flex items-center gap-2 rounded-pssfp-button border border-dashed border-gray-300 bg-gray-50 px-3 py-2.5 text-sm text-gray-500"
                >
                  <m.Icon size={16} aria-hidden="true" className="opacity-60" />
                  <span className="flex-1">{m.label}</span>
                  <span className="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-600">
                    Bientôt
                  </span>
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}
    </section>
  );
}
