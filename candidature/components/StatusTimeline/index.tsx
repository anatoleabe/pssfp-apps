import type { MyCandidature } from '@/lib/api/client';
import { formatDateFr } from '@/lib/format/date';

interface TimelineEvent {
  id: string;
  label: string;
  date: string | null;
  status: 'done' | 'current' | 'pending' | 'cancelled';
  body?: string;
}

function buildEvents(candidature: MyCandidature): TimelineEvent[] {
  const isWithdrawn = candidature.withdrawn_at !== null;
  const events: TimelineEvent[] = [
    {
      id: 'inscription',
      label: 'Inscription en ligne',
      date: null,
      status: 'done',
    },
    {
      id: 'soumission',
      label: 'Dossier soumis',
      date: candidature.submitted_at,
      status: candidature.submitted_at
        ? 'done'
        : isWithdrawn
        ? 'cancelled'
        : 'current',
    },
    {
      id: 'frais',
      label: 'Frais réglés',
      date: candidature.date_paiement,
      status: candidature.frais_paye ? 'done' : 'pending',
    },
    {
      id: 'examen',
      label: 'Examen par le comité',
      date: null,
      status:
        candidature.statut === 'accepte' || candidature.statut === 'refuse'
          ? 'done'
          : candidature.submitted_at && !isWithdrawn
          ? 'current'
          : 'pending',
    },
    {
      id: 'decision',
      label: 'Décision rendue',
      date: candidature.decided_at,
      status:
        candidature.statut === 'accepte'
          ? 'done'
          : candidature.statut === 'refuse'
          ? 'cancelled'
          : 'pending',
      body:
        candidature.statut === 'accepte'
          ? 'Acceptée — vous serez contacté(e) pour la suite.'
          : candidature.statut === 'refuse'
          ? 'Non retenue pour cette session.'
          : undefined,
    },
  ];

  if (isWithdrawn) {
    events.push({
      id: 'retrait',
      label: 'Candidature retirée',
      date: candidature.withdrawn_at,
      status: 'cancelled',
    });
  }

  return events;
}

const STATUS_CLASS: Record<TimelineEvent['status'], string> = {
  done: 'border-emerald-500 bg-emerald-100 text-emerald-700',
  current: 'border-[#6B2FA0] bg-[#EDE7F6] text-[#6B2FA0]',
  pending: 'border-gray-300 bg-white text-gray-400',
  cancelled: 'border-red-300 bg-red-50 text-red-700',
};

export function StatusTimeline({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const events = buildEvents(candidature);

  return (
    <ol className="relative space-y-6 border-l border-gray-200 pl-6" data-testid="status-timeline">
      {events.map((evt) => (
        <li key={evt.id} className="relative">
          <span
            aria-hidden
            className={`absolute -left-[34px] flex h-6 w-6 items-center justify-center rounded-full border-2 text-xs font-bold ${STATUS_CLASS[evt.status]}`}
          >
            {evt.status === 'done' ? '✓' : evt.status === 'cancelled' ? '×' : '•'}
          </span>
          <div>
            <h3 className="font-medium text-[#333]">{evt.label}</h3>
            {evt.date && (
              <p className="text-xs text-gray-500">{formatDateFr(evt.date)}</p>
            )}
            {evt.body && (
              <p className="mt-1 text-sm text-[#666]">{evt.body}</p>
            )}
          </div>
        </li>
      ))}
    </ol>
  );
}
