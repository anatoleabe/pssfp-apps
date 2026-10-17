import type { ReactNode } from 'react';
import { Inbox, TriangleAlert } from 'lucide-react';

interface StateMessageProps {
  kind: 'empty' | 'error';
  title: string;
  body?: string;
  action?: ReactNode;
  /**
   * Faux quand la page annonce déjà le résultat dans sa propre région `role="status"` :
   * l'état vide n'en crée pas une seconde (une seule région vivante par page).
   */
  live?: boolean;
}

/** État vide (role=status) ou d'erreur (role=alert) d'une liste ou d'une page. */
export function StateMessage({ kind, title, body, action, live = true }: StateMessageProps): JSX.Element {
  const isError = kind === 'error';
  const Icon = isError ? TriangleAlert : Inbox;
  return (
    <div
      role={live ? (isError ? 'alert' : 'status') : undefined}
      data-testid={isError ? 'state-error' : 'state-empty'}
      className={`flex flex-col items-start gap-4 rounded-pssfp-card border p-6 sm:flex-row sm:p-8 ${
        isError ? 'border-pssfp-or/60 bg-[#F6ECD9]' : 'border-dashed border-pssfp-lavande bg-white'
      }`}
    >
      <span
        className={`inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full ${
          isError ? 'bg-white text-pssfp-or-dark' : 'bg-pssfp-ivoire text-pssfp-prune'
        }`}
      >
        <Icon size={20} strokeWidth={1.75} aria-hidden="true" />
      </span>
      <div className="space-y-2">
        <p className="font-heading text-2xl font-semibold leading-tight text-pssfp-prune">{title}</p>
        {body && <p className="text-pssfp-graphite">{body}</p>}
        {action && <div className="pt-2">{action}</div>}
      </div>
    </div>
  );
}
