import { forwardRef, type HTMLAttributes } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../../utils/cn';

const cardVariants = cva(
  'rounded-pssfp-card transition-all duration-300 ease-pssfp-out-expo',
  {
    variants: {
      variant: {
        default:
          'bg-white border border-[#F4EFFA] shadow-pssfp-soft hover:-translate-y-1 hover:shadow-pssfp-elevated hover:border-[#5C3A7E]/40',
        flat:
          'bg-white border border-[#F4EFFA]',
        glass:
          'border border-white/30 bg-white/60 shadow-pssfp-soft backdrop-blur-2xl',
        gradient:
          'bg-gradient-prune-or text-white shadow-pssfp-elevated hover:-translate-y-1 hover:shadow-pssfp-floating',
        lavande:
          'bg-gradient-lavande-blanc border border-[#F4EFFA] shadow-pssfp-soft hover:-translate-y-1 hover:shadow-pssfp-elevated',
        outline:
          'bg-transparent border-2 border-[#4A2E67]/20 hover:border-[#4A2E67]/60 hover:shadow-pssfp-soft',
      },
      padding: {
        none: '',
        sm: 'p-4',
        md: 'p-6',
        lg: 'p-8',
      },
      interactive: {
        true: 'cursor-pointer focus-within:ring-2 focus-within:ring-[#4A2E67] focus-within:ring-offset-2',
        false: '',
      },
    },
    defaultVariants: {
      variant: 'default',
      padding: 'md',
      interactive: false,
    },
  },
);

export interface CardProps
  extends HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof cardVariants> {}

export const Card = forwardRef<HTMLDivElement, CardProps>(
  ({ className, variant, padding, interactive, children, ...props }, ref) => (
    <div
      ref={ref}
      className={cn(cardVariants({ variant, padding, interactive }), className)}
      {...props}
    >
      {children}
    </div>
  ),
);
Card.displayName = 'Card';

export const CardHeader = forwardRef<HTMLDivElement, HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div
      ref={ref}
      className={cn('flex flex-col gap-1.5', className)}
      {...props}
    />
  ),
);
CardHeader.displayName = 'CardHeader';

export const CardTitle = forwardRef<HTMLHeadingElement, HTMLAttributes<HTMLHeadingElement>>(
  ({ className, ...props }, ref) => (
    <h3
      ref={ref}
      className={cn('font-heading text-pssfp-h3 font-bold text-[#333]', className)}
      {...props}
    />
  ),
);
CardTitle.displayName = 'CardTitle';

export const CardDescription = forwardRef<HTMLParagraphElement, HTMLAttributes<HTMLParagraphElement>>(
  ({ className, ...props }, ref) => (
    <p
      ref={ref}
      className={cn('text-sm leading-relaxed text-[#666]', className)}
      {...props}
    />
  ),
);
CardDescription.displayName = 'CardDescription';

export const CardContent = forwardRef<HTMLDivElement, HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn('mt-4', className)} {...props} />
  ),
);
CardContent.displayName = 'CardContent';

export const CardFooter = forwardRef<HTMLDivElement, HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div
      ref={ref}
      className={cn('mt-5 flex items-center gap-3', className)}
      {...props}
    />
  ),
);
CardFooter.displayName = 'CardFooter';

export { cardVariants };
