import type { MyCandidature } from '@/lib/api/client';
import { formatDateFr } from '@/lib/format/date';

const STATUT_BADGE: Record<string, { label: string; className: string }> = {
  postulant: { label: 'Postulant', className: 'bg-amber-100 text-amber-800 border-amber-300' },
  candidat: { label: 'Candidat', className: 'bg-violet-100 text-violet-800 border-violet-300' },
  accepte: { label: 'Accepté', className: 'bg-emerald-100 text-emerald-800 border-emerald-300' },
  refuse: { label: 'Refusé', className: 'bg-red-100 text-red-800 border-red-300' },
};

const FALLBACK_BADGE = { label: 'Postulant', className: 'bg-amber-100 text-amber-800 border-amber-300' };

export function DossierStatutCard({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const badge = STATUT_BADGE[candidature.statut] ?? FALLBACK_BADGE;
  const isWithdrawn = candidature.withdrawn_at !== null;

  return (
    <section
      aria-labelledby="statut-heading"
      className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
    >
      <h2 id="statut-heading" className="font-heading text-lg font-bold text-[#6B2FA0]">
        État de mon dossier
      </h2>

      <div className="mt-4 flex items-center gap-3">
        <span
          className={`inline-flex items-center rounded-full border px-3 py-1 text-sm font-medium ${badge.className}`}
          data-testid="dossier-statut-badge"
        >
          {isWithdrawn ? 'Retiré' : badge.label}
        </span>
        <span className="font-mono text-sm text-gray-600">{candidature.numero_dossier}</span>
      </div>

      <dl className="mt-4 grid gap-3 text-sm md:grid-cols-2">
        {candidature.campagne && (
          <div>
            <dt className="text-gray-500">Campagne</dt>
            <dd className="font-medium text-[#333]">{candidature.campagne.nom}</dd>
          </div>
        )}
        {candidature.submitted_at && (
          <div>
            <dt className="text-gray-500">Soumis le</dt>
            <dd className="font-medium text-[#333]">{formatDateFr(candidature.submitted_at)}</dd>
          </div>
        )}
        {candidature.decided_at && (
          <div>
            <dt className="text-gray-500">Décidé le</dt>
            <dd className="font-medium text-[#333]">{formatDateFr(candidature.decided_at)}</dd>
          </div>
        )}
        {candidature.withdrawn_at && (
          <div>
            <dt className="text-gray-500">Retiré le</dt>
            <dd className="font-medium text-[#333]">{formatDateFr(candidature.withdrawn_at)}</dd>
          </div>
        )}
      </dl>
    </section>
  );
}
