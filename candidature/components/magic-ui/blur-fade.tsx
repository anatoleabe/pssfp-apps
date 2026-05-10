'use client';

import { type ReactNode, useRef } from 'react';
import {
  AnimatePresence,
  motion,
  useInView,
  useReducedMotion,
  type Variants,
} from 'framer-motion';

export interface BlurFadeProps {
  children: ReactNode;
  className?: string;
  variant?: { hidden: { y: number }; visible: { y: number } };
  duration?: number;
  delay?: number;
  yOffset?: number;
  inView?: boolean;
  inViewMargin?: string;
  blur?: string;
}

export function BlurFade({
  children,
  className,
  variant,
  duration = 0.4,
  delay = 0,
  yOffset = 6,
  inView = false,
  inViewMargin = '-50px',
  blur = '6px',
}: BlurFadeProps): JSX.Element {
  const ref = useRef<HTMLDivElement>(null);
  const reduceMotion = useReducedMotion();
  const inViewResult = useInView(ref, {
    once: true,
    margin: inViewMargin as `${number}px`,
  });
  const isInView = !inView || inViewResult;

  const defaultVariants: Variants = {
    hidden: { y: yOffset, opacity: 0, filter: `blur(${blur})` },
    visible: { y: -yOffset, opacity: 1, filter: 'blur(0px)' },
  };
  const combined = variant ?? defaultVariants;

  if (reduceMotion) {
    return <div ref={ref} className={className}>{children}</div>;
  }

  return (
    <AnimatePresence>
      <motion.div
        ref={ref}
        initial="hidden"
        animate={isInView ? 'visible' : 'hidden'}
        exit="hidden"
        variants={combined}
        transition={{ delay: 0.04 + delay, duration, ease: [0.16, 1, 0.3, 1] }}
        className={className}
      >
        {children}
      </motion.div>
    </AnimatePresence>
  );
}
