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
      className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
    >
      <h2 id="frais-heading" className="font-heading text-lg font-bold text-[#6B2FA0]">
        Frais de candidature
      </h2>

      {isPaid ? (
        <div
          role="status"
          data-testid="dossier-frais-payes"
          className="mt-4 rounded-md border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900"
        >
          <p className="font-medium">✅ Frais réglés</p>
          <dl className="mt-2 grid gap-1 md:grid-cols-3">
            <div>
              <dt className="text-emerald-700">Mode</dt>
              <dd>{MODE_LABELS[candidature.mode_paiement ?? ''] ?? candidature.mode_paiement ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-emerald-700">Référence</dt>
              <dd className="font-mono">{candidature.reference_paiement ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-emerald-700">Date</dt>
              <dd>{candidature.date_paiement ? formatDateFr(candidature.date_paiement) : '—'}</dd>
            </div>
          </dl>
        </div>
      ) : (
        <div className="mt-4 space-y-5">
          <div className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <p>
              <strong>Montant à régler : 50 000 FCFA</strong>
              <br />
              Le paiement n'est requis qu'après la soumission en ligne. Il est nécessaire
              pour valider l'examen administratif de votre dossier.
            </p>
          </div>

          <div className="rounded-md border border-[#EDE7F6] bg-white p-4">
            <p className="text-sm font-semibold text-[#6B2FA0]">Paiement par CREMINCAM (V1)</p>
            <ol className="mt-3 list-decimal space-y-1 pl-5 text-sm text-[#333]">
              <li>Se rendre dans une agence CREMINCAM.</li>
              <li>
                Présenter le numéro de dossier{' '}
                <span className="rounded bg-[#EDE7F6] px-2 py-0.5 font-mono text-[#6B2FA0]">{numero}</span>.
              </li>
              <li>Payer 50 000 FCFA et conserver le récépissé bancaire.</li>
              <li>Apporter le récépissé lors du dépôt physique au PSSFP.</li>
            </ol>
          </div>

          <div className="rounded-md border border-dashed border-gray-300 bg-gray-50 p-4">
            <p className="text-sm font-semibold text-gray-600">
              Bientôt disponible — paiement en ligne
            </p>
            <ul className="mt-3 grid gap-2 text-sm text-gray-500 md:grid-cols-3">
              <li className="flex items-center gap-2">
                <span aria-hidden>📱</span>
                Orange Money
                <span className="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] uppercase tracking-wide text-gray-600">
                  À venir
                </span>
              </li>
              <li className="flex items-center gap-2">
                <span aria-hidden>📱</span>
                MTN Mobile Money
                <span className="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] uppercase tracking-wide text-gray-600">
                  À venir
                </span>
              </li>
              <li className="flex items-center gap-2">
                <span aria-hidden>💳</span>
                Carte Visa / Mastercard
                <span className="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] uppercase tracking-wide text-gray-600">
                  À venir
                </span>
              </li>
            </ul>
          </div>
        </div>
      )}
    </section>
  );
}
