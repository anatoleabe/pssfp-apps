import { forwardRef, type SVGAttributes } from 'react';
import { cn } from '../../utils/cn';

export interface LogoProps extends SVGAttributes<SVGSVGElement> {
  variant?: 'full' | 'mark';
  title?: string;
}

export const Logo = forwardRef<SVGSVGElement, LogoProps>(
  (
    {
      variant = 'full',
      title = 'PSSFP — Programme Supérieur de Spécialisation en Finances Publiques',
      className,
      ...props
    },
    ref,
  ) => {
    if (variant === 'mark') {
      return (
        <svg
          ref={ref}
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 48 48"
          role="img"
          aria-label={title}
          className={cn('h-10 w-10', className)}
          {...props}
        >
          <title>{title}</title>
          <rect width="48" height="48" rx="8" fill="#6B2FA0" />
          <text
            x="24"
            y="30"
            textAnchor="middle"
            fontFamily="Playfair Display, serif"
            fontSize="20"
            fontWeight="700"
            fill="#FFFFFF"
          >
            P
          </text>
        </svg>
      );
    }

    return (
      <svg
        ref={ref}
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 200 48"
        role="img"
        aria-label={title}
        className={cn('h-10 w-auto', className)}
        {...props}
      >
        <title>{title}</title>
        <rect width="48" height="48" rx="8" fill="#6B2FA0" />
        <text
          x="24"
          y="30"
          textAnchor="middle"
          fontFamily="Playfair Display, serif"
          fontSize="20"
          fontWeight="700"
          fill="#FFFFFF"
        >
          P
        </text>
        <text
          x="60"
          y="22"
          fontFamily="Playfair Display, serif"
          fontSize="18"
          fontWeight="700"
          fill="#6B2FA0"
        >
          PSSFP
        </text>
        <text
          x="60"
          y="38"
          fontFamily="Inter, sans-serif"
          fontSize="9"
          fill="#666666"
        >
          Finances Publiques
        </text>
      </svg>
    );
  },
);

Logo.displayName = 'Logo';
