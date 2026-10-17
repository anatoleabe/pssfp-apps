import { Coins, Lock, LockOpen, type LucideIcon } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { AccessMode } from '@/lib/api/types';
import { formatPrice } from '@/lib/format';

/*
 * Teintes des maquettes validées (2026-10-17). Contrastes :
 * vert #1D5B46 / #E6F0EC 6,84:1 · prune #4A2E67 / #ECE6F3 9,15:1 · brun #7A5A1C / #F6ECD9 5,42:1.
 */
const BADGES: Record<AccessMode, { className: string; Icon: LucideIcon }> = {
  libre: { className: 'bg-[#E6F0EC] text-[#1D5B46]', Icon: LockOpen },
  reserve: { className: 'bg-[#ECE6F3] text-pssfp-prune', Icon: Lock },
  payant: { className: 'bg-[#F6ECD9] text-pssfp-or-dark', Icon: Coins },
};

interface AccessBadgeProps {
  mode: AccessMode;
  price?: number | null;
}

export function AccessBadge({ mode, price = null }: AccessBadgeProps): JSX.Element {
  const t = useTranslations('access');
  const { className, Icon } = BADGES[mode];
  const label = mode === 'payant' && price !== null ? t('payantPrice', { price: formatPrice(price) }) : t(mode);
  return (
    <span
      data-testid="access-badge"
      className={`inline-flex items-center gap-1.5 rounded px-2 py-0.5 font-ui text-xs font-medium ${className}`}
    >
      <Icon size={12} strokeWidth={2} aria-hidden="true" />
      {label}
    </span>
  );
}
