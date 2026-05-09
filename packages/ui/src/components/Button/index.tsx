import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../../utils/cn';

const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 rounded-pssfp-button font-medium transition-all duration-200 ease-pssfp-out-expo focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 active:scale-[0.98]',
  {
    variants: {
      variant: {
        primary: [
          'relative bg-[#6B2FA0] text-white shadow-pssfp-soft',
          'hover:bg-gradient-violet hover:shadow-pssfp-elevated hover:-translate-y-0.5',
          'focus-visible:ring-[#6B2FA0]',
        ].join(' '),
        secondary: [
          'border-2 border-[#6B2FA0] bg-transparent text-[#6B2FA0]',
          'hover:bg-[#6B2FA0] hover:text-white hover:shadow-pssfp-elevated',
          'focus-visible:ring-[#6B2FA0]',
        ].join(' '),
        cta: [
          'relative overflow-hidden bg-gradient-violet-or text-white shadow-pssfp-elevated',
          'hover:shadow-pssfp-floating hover:-translate-y-0.5',
          'focus-visible:ring-[#C9A227]',
          'before:absolute before:inset-0 before:-translate-x-full before:bg-gradient-to-r before:from-transparent before:via-white/25 before:to-transparent before:transition-transform before:duration-700',
          'hover:before:translate-x-full',
        ].join(' '),
        ghost: [
          'relative bg-transparent text-[#6B2FA0]',
          'hover:bg-[#EDE7F6]',
          'focus-visible:ring-[#6B2FA0]',
          'after:absolute after:bottom-1 after:left-3 after:right-3 after:h-px after:origin-left after:scale-x-0 after:bg-[#6B2FA0] after:transition-transform after:duration-300',
          'hover:after:scale-x-100',
        ].join(' '),
        accent: [
          'bg-[#C9A227] text-[#1A0A2E] shadow-pssfp-soft',
          'hover:bg-gradient-or-soft hover:shadow-pssfp-elevated hover:-translate-y-0.5',
          'focus-visible:ring-[#C9A227]',
        ].join(' '),
        outline: [
          'border border-gray-300 bg-white text-[#333]',
          'hover:border-[#6B2FA0] hover:text-[#6B2FA0] hover:shadow-pssfp-soft',
          'focus-visible:ring-[#6B2FA0]',
        ].join(' '),
      },
      size: {
        sm: 'h-9 px-3.5 text-sm',
        md: 'h-11 px-5 text-base',
        lg: 'h-13 px-7 text-lg',
      },
      fullWidth: {
        true: 'w-full',
        false: '',
      },
    },
    defaultVariants: {
      variant: 'primary',
      size: 'md',
      fullWidth: false,
    },
  },
);

export interface ButtonProps
  extends ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  loading?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  (
    { className, variant, size, fullWidth, type = 'button', loading, disabled, children, ...props },
    ref,
  ) => (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cn(buttonVariants({ variant, size, fullWidth }), className)}
      {...props}
    >
      {loading ? (
        <>
          <svg
            className="h-4 w-4 animate-spin"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
          >
            <circle
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              strokeWidth="3"
              strokeOpacity="0.25"
            />
            <path
              d="M22 12a10 10 0 0 1-10 10"
              stroke="currentColor"
              strokeWidth="3"
              strokeLinecap="round"
            />
          </svg>
          <span>{children}</span>
        </>
      ) : (
        children
      )}
    </button>
  ),
);

Button.displayName = 'Button';

export { buttonVariants };
