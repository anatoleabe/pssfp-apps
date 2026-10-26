import { useLocale, useTranslations } from 'next-intl';
import type { MyCandidature } from '@/lib/api/client';
import { formatDateLong } from '@/lib/format/date';

interface TimelineEvent {
  id: string;
  label: string;
  date: string | null;
  status: 'done' | 'current' | 'pending' | 'cancelled';
  body?: string;
}

type Translate = ReturnType<typeof useTranslations<'dossier.timeline'>>;

function buildEvents(candidature: MyCandidature, t: Translate): TimelineEvent[] {
  const isWithdrawn = candidature.withdrawn_at !== null;
  const events: TimelineEvent[] = [
    {
      id: 'inscription',
      label: t('inscription'),
      date: null,
      status: 'done',
    },
    {
      id: 'soumission',
      label: t('soumission'),
      date: candidature.submitted_at,
      status: candidature.submitted_at
        ? 'done'
        : isWithdrawn
        ? 'cancelled'
        : 'current',
    },
    {
      id: 'frais',
      label: t('frais'),
      date: candidature.date_paiement,
      status: candidature.frais_paye ? 'done' : 'pending',
    },
    {
      id: 'examen',
      label: t('examen'),
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
      label: t('decision'),
      date: candidature.decided_at,
      status:
        candidature.statut === 'accepte'
          ? 'done'
          : candidature.statut === 'refuse'
          ? 'cancelled'
          : 'pending',
      body:
        candidature.statut === 'accepte'
          ? t('retenu')
          : candidature.statut === 'refuse'
          ? t('nonRetenu')
          : undefined,
    },
  ];

  if (isWithdrawn) {
    events.push({
      id: 'retrait',
      label: t('retrait'),
      date: candidature.withdrawn_at,
      status: 'cancelled',
    });
  }

  return events;
}

const STATUS_CLASS: Record<TimelineEvent['status'], string> = {
  done: 'border-emerald-500 bg-emerald-100 text-emerald-700',
  current: 'border-[#4A2E67] bg-[#F4EFFA] text-[#4A2E67]',
  pending: 'border-gray-300 bg-white text-gray-400',
  // Ton sobre : une non-sélection ou un retrait n'est pas une erreur.
  cancelled: 'border-[#A592BD] bg-[#F4EFFA] text-[#3A2452]',
};

export function StatusTimeline({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const t = useTranslations('dossier.timeline');
  const locale = useLocale();
  const events = buildEvents(candidature, t);

  return (
    <ol className="relative space-y-6 border-l border-gray-200 pl-6" data-testid="status-timeline">
      {events.map((evt) => (
        <li key={evt.id} className="relative" aria-current={evt.status === 'current' ? 'step' : undefined}>
          <span
            aria-hidden
            className={`absolute -left-[34px] flex h-6 w-6 items-center justify-center rounded-full border-2 text-xs font-bold ${STATUS_CLASS[evt.status]}`}
          >
            {evt.status === 'done' ? '✓' : evt.status === 'cancelled' ? '×' : '•'}
          </span>
          <div>
            <h3 className="font-medium text-[#333]">
              {evt.label}
              <span className="sr-only"> — {t(`state.${evt.status}`)}</span>
            </h3>
            {evt.date && (
              <p className="text-xs text-gray-500">{formatDateLong(evt.date, locale)}</p>
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
