import { Coins, Lock, LockOpen, type LucideIcon } from 'lucide-react';
import { useFormatter, useTranslations } from 'next-intl';
import type { AccessMode } from '@/lib/api/types';
import { PRICE_FORMAT } from '@/lib/format';

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
  /** Sur une couverture : contour blanc et ombre légère pour détacher la pastille. */
  floating?: boolean;
}

export function AccessBadge({ mode, price = null, floating = false }: AccessBadgeProps): JSX.Element {
  const t = useTranslations('access');
  const format = useFormatter();
  const { className, Icon } = BADGES[mode];
  const label = mode === 'payant' && price !== null ? t('payantPrice', { price: format.number(price, PRICE_FORMAT) }) : t(mode);
  return (
    <span
      data-testid="access-badge"
      className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-ui text-xs font-medium ${className} ${floating ? 'shadow-pssfp-soft ring-1 ring-white' : ''}`}
    >
      <Icon size={12} strokeWidth={2} aria-hidden="true" />
      {label}
    </span>
  );
}
