import { CheckCircle2, Clock, XCircle, FileCheck, Ban } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { MyCandidature } from '@/lib/api/client';
import { formatDateFr } from '@/lib/format/date';

interface StatutInfo {
  label: string;
  badge: string;
  Icon: LucideIcon;
  iconColor: string;
}

const STATUT_INFO: Record<string, StatutInfo> = {
  postulant: {
    label: 'Postulant',
    badge: 'bg-amber-50 text-amber-800 border-amber-200',
    Icon: Clock,
    iconColor: 'text-amber-600 bg-amber-100',
  },
  candidat: {
    label: 'Candidat',
    badge: 'bg-violet-50 text-violet-800 border-violet-200',
    Icon: FileCheck,
    iconColor: 'text-violet-600 bg-violet-100',
  },
  accepte: {
    label: 'Accepté',
    badge: 'bg-emerald-50 text-emerald-800 border-emerald-200',
    Icon: CheckCircle2,
    iconColor: 'text-emerald-600 bg-emerald-100',
  },
  refuse: {
    label: 'Refusé',
    badge: 'bg-red-50 text-red-800 border-red-200',
    Icon: XCircle,
    iconColor: 'text-red-600 bg-red-100',
  },
};

const FALLBACK_INFO: StatutInfo = {
  label: 'Postulant',
  badge: 'bg-amber-50 text-amber-800 border-amber-200',
  Icon: Clock,
  iconColor: 'text-amber-600 bg-amber-100',
};

const WITHDRAWN_INFO: StatutInfo = {
  label: 'Retiré',
  badge: 'bg-gray-50 text-gray-800 border-gray-200',
  Icon: Ban,
  iconColor: 'text-gray-600 bg-gray-100',
};

export function DossierStatutCard({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const isWithdrawn = candidature.withdrawn_at !== null;
  const info = isWithdrawn
    ? WITHDRAWN_INFO
    : STATUT_INFO[candidature.statut] ?? FALLBACK_INFO;
  const Icon = info.Icon;

  return (
    <section
      aria-labelledby="statut-heading"
      className="relative overflow-hidden rounded-pssfp-card border border-[#EDE7F6] bg-white p-6 shadow-pssfp-soft md:p-7"
    >
      {/* Halo décoratif gauche */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -left-12 -top-12 h-44 w-44 rounded-full opacity-30 blur-3xl"
        style={{ background: 'radial-gradient(circle, rgba(107, 47, 160, 0.18) 0%, transparent 70%)' }}
      />

      <div className="relative flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="pssfp-eyebrow">État de mon dossier</p>
          <h2
            id="statut-heading"
            className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A0A2E]"
          >
            Suivi en temps réel
          </h2>
        </div>
        <span
          className={`inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-semibold ${info.badge}`}
          data-testid="dossier-statut-badge"
        >
          <span aria-hidden="true" className={`inline-flex h-7 w-7 items-center justify-center rounded-full ${info.iconColor}`}>
            <Icon size={14} />
          </span>
          {info.label}
        </span>
      </div>

      <div className="relative mt-5 inline-flex items-center gap-2 rounded-pssfp-button bg-[#FAF7FF] px-3 py-2 font-mono text-sm text-[#6B2FA0]">
        <span className="text-xs uppercase tracking-wider text-[#888]">N°</span>
        {candidature.numero_dossier}
      </div>

      {(candidature.campagne || candidature.submitted_at || candidature.decided_at || candidature.withdrawn_at) && (
        <dl className="relative mt-6 grid gap-4 border-t border-[#EDE7F6] pt-6 text-sm sm:grid-cols-2">
          {candidature.campagne && (
            <div>
              <dt className="text-xs uppercase tracking-wider text-[#888]">Campagne</dt>
              <dd className="mt-1 font-heading font-bold text-[#6B2FA0]">{candidature.campagne.nom}</dd>
            </div>
          )}
          {candidature.submitted_at && (
            <div>
              <dt className="text-xs uppercase tracking-wider text-[#888]">Soumis le</dt>
              <dd className="mt-1 font-medium text-[#333]">{formatDateFr(candidature.submitted_at)}</dd>
            </div>
          )}
          {candidature.decided_at && (
            <div>
              <dt className="text-xs uppercase tracking-wider text-[#888]">Décidé le</dt>
              <dd className="mt-1 font-medium text-[#333]">{formatDateFr(candidature.decided_at)}</dd>
            </div>
          )}
          {candidature.withdrawn_at && (
            <div>
              <dt className="text-xs uppercase tracking-wider text-[#888]">Retiré le</dt>
              <dd className="mt-1 font-medium text-[#333]">{formatDateFr(candidature.withdrawn_at)}</dd>
            </div>
          )}
        </dl>
      )}
    </section>
  );
}
