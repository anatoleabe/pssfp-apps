import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../../utils/cn';

const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 rounded-pssfp-button font-medium transition-all duration-200 ease-pssfp-out-expo focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 active:scale-[0.98]',
  {
    variants: {
      variant: {
        primary: [
          'relative bg-[#4A2E67] text-white shadow-pssfp-soft',
          'hover:bg-gradient-prune hover:shadow-pssfp-elevated hover:-translate-y-0.5',
          'focus-visible:ring-[#4A2E67]',
        ].join(' '),
        secondary: [
          'border-2 border-[#4A2E67] bg-transparent text-[#4A2E67]',
          'hover:bg-[#4A2E67] hover:text-white hover:shadow-pssfp-elevated',
          'focus-visible:ring-[#4A2E67]',
        ].join(' '),
        cta: [
          'relative overflow-hidden bg-gradient-prune-or text-white shadow-pssfp-elevated',
          'hover:shadow-pssfp-floating hover:-translate-y-0.5',
          'focus-visible:ring-[#D4AF6A]',
          'before:absolute before:inset-0 before:-translate-x-full before:bg-gradient-to-r before:from-transparent before:via-white/25 before:to-transparent before:transition-transform before:duration-700',
          'hover:before:translate-x-full',
        ].join(' '),
        ghost: [
          'relative bg-transparent text-[#4A2E67]',
          'hover:bg-[#F4EFFA]',
          'focus-visible:ring-[#4A2E67]',
          'after:absolute after:bottom-1 after:left-3 after:right-3 after:h-px after:origin-left after:scale-x-0 after:bg-[#4A2E67] after:transition-transform after:duration-300',
          'hover:after:scale-x-100',
        ].join(' '),
        accent: [
          'bg-[#D4AF6A] text-[#1A1A1A] shadow-pssfp-soft',
          'hover:bg-gradient-or hover:shadow-pssfp-elevated hover:-translate-y-0.5',
          'focus-visible:ring-[#D4AF6A]',
        ].join(' '),
        outline: [
          'border border-gray-300 bg-white text-[#333]',
          'hover:border-[#4A2E67] hover:text-[#4A2E67] hover:shadow-pssfp-soft',
          'focus-visible:ring-[#4A2E67]',
        ].join(' '),
      },
      size: {
        sm: 'h-9 px-3.5 text-sm',
        md: 'h-11 px-5 text-base',
        lg: 'h-14 px-7 text-lg',
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
