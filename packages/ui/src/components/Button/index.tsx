import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../../utils/cn';

const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 rounded-md font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
  {
    variants: {
      variant: {
        primary:
          'bg-[#6B2FA0] text-white hover:bg-[#9B59B6] focus-visible:ring-[#6B2FA0]',
        secondary:
          'bg-[#EDE7F6] text-[#6B2FA0] hover:bg-[#9B59B6] hover:text-white focus-visible:ring-[#6B2FA0]',
        ghost:
          'bg-transparent text-[#6B2FA0] hover:bg-[#EDE7F6] focus-visible:ring-[#6B2FA0]',
        accent:
          'bg-[#C9A227] text-white hover:opacity-90 focus-visible:ring-[#C9A227]',
      },
      size: {
        sm: 'h-9 px-3 text-sm',
        md: 'h-10 px-4 text-base',
        lg: 'h-12 px-6 text-lg',
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
    VariantProps<typeof buttonVariants> {}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, fullWidth, type = 'button', ...props }, ref) => (
    <button
      ref={ref}
      type={type}
      className={cn(buttonVariants({ variant, size, fullWidth }), className)}
      {...props}
    />
  ),
);

Button.displayName = 'Button';

export { buttonVariants };
