import type { ReactNode } from 'react';
import { cn } from '../../lib/cn';

export interface BentoGridProps {
  className?: string;
  children: ReactNode;
}

export interface BentoCardProps {
  className?: string;
  /** Span colonnes desktop (1-3). */
  colSpan?: 1 | 2 | 3;
  /** Span lignes desktop (1-2). */
  rowSpan?: 1 | 2;
  /** Variante visuelle. */
  variant?: 'default' | 'prune' | 'lavande' | 'or';
  children: ReactNode;
}

const colSpanMap: Record<NonNullable<BentoCardProps['colSpan']>, string> = {
  1: 'lg:col-span-1',
  2: 'lg:col-span-2',
  3: 'lg:col-span-3',
};

const rowSpanMap: Record<NonNullable<BentoCardProps['rowSpan']>, string> = {
  1: 'lg:row-span-1',
  2: 'lg:row-span-2',
};

const variantMap: Record<NonNullable<BentoCardProps['variant']>, string> = {
  default:
    'bg-white border border-[#F4EFFA] shadow-pssfp-soft hover:shadow-pssfp-elevated',
  prune:
    'bg-[#4A2E67] text-white border border-transparent shadow-pssfp-elevated hover:shadow-pssfp-floating',
  lavande:
    'bg-[#FAF7F2] border border-[#F4EFFA] shadow-pssfp-soft hover:shadow-pssfp-elevated',
  or:
    'bg-[#D4AF6A] text-[#1A1A1A] border border-transparent shadow-pssfp-elevated hover:shadow-pssfp-floating',
};

export function BentoGrid({ className, children }: BentoGridProps): JSX.Element {
  return (
    <div
      className={cn(
        'grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:auto-rows-[minmax(180px,auto)]',
        className,
      )}
    >
      {children}
    </div>
  );
}

export function BentoCard({
  className,
  colSpan = 1,
  rowSpan = 1,
  variant = 'default',
  children,
}: BentoCardProps): JSX.Element {
  return (
    <div
      className={cn(
        'group relative flex flex-col overflow-hidden rounded-pssfp-card p-6 transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1',
        colSpanMap[colSpan],
        rowSpanMap[rowSpan],
        variantMap[variant],
        className,
      )}
    >
      {children}
    </div>
  );
}
