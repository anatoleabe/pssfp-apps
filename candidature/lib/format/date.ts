/**
 * Helpers date pour le compte à rebours et le formatage candidat.
 */

export interface CountdownParts {
  totalSeconds: number;
  days: number;
  hours: number;
  minutes: number;
  seconds: number;
  status: 'green' | 'orange' | 'red' | 'expired';
}

export function computeCountdown(targetIso: string): CountdownParts {
  const targetMs = new Date(targetIso).getTime();
  const nowMs = Date.now();
  const diffMs = targetMs - nowMs;

  if (Number.isNaN(targetMs) || diffMs <= 0) {
    return { totalSeconds: 0, days: 0, hours: 0, minutes: 0, seconds: 0, status: 'expired' };
  }

  const totalSeconds = Math.floor(diffMs / 1000);
  const days = Math.floor(totalSeconds / 86400);
  const hours = Math.floor((totalSeconds % 86400) / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  let status: CountdownParts['status'] = 'green';
  if (days <= 3) {
    status = 'red';
  } else if (days <= 14) {
    status = 'orange';
  }

  return { totalSeconds, days, hours, minutes, seconds, status };
}

export function formatDateFr(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return iso;
  }
  return new Intl.DateTimeFormat('fr-FR', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  }).format(date);
}
