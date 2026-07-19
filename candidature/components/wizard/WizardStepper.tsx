'use client';

import { Check } from 'lucide-react';
import { motion, useReducedMotion } from 'framer-motion';
import { cn } from '@/lib/cn';

interface WizardStepperProps {
  current: number; // 1..N
  steps: Array<{ id: number; label: string }>;
}

/**
 * Stepper visuel premium avec progression animée Framer Motion.
 *
 * - Cercle plein prune pour étape actuelle (anneau pulsé subtil)
 * - Check vert pour étapes complétées
 * - Cercle outline neutre pour étapes futures
 * - Connecteurs avec remplissage animé (couleur plate)
 * - Labels masqués sur mobile (steppers compacts) + barre de progression
 */
export function WizardStepper({ current, steps }: WizardStepperProps): JSX.Element {
  const reduceMotion = useReducedMotion();
  const totalSteps = steps.length;
  const completedRatio = Math.max(0, Math.min(1, (current - 1) / Math.max(1, totalSteps - 1)));

  return (
    <nav aria-label="Progression du formulaire" className="mb-10">
      {/* Mobile: compact summary */}
      <div className="mb-4 flex items-center justify-between md:hidden">
        <p className="font-heading text-sm font-bold text-[#4A2E67]">
          Étape {current} / {totalSteps}
        </p>
        <p className="text-xs text-[#666]">{steps[current - 1]?.label}</p>
      </div>

      {/* Bar de progression mobile */}
      <div className="relative mb-6 h-1.5 w-full overflow-hidden rounded-full bg-[#F4EFFA] md:hidden">
        <motion.div
          className="h-full rounded-full bg-[#4A2E67]"
          initial={false}
          animate={{ width: `${completedRatio * 100}%` }}
          transition={
            reduceMotion ? { duration: 0 } : { type: 'spring', stiffness: 100, damping: 20 }
          }
        />
      </div>

      {/* Desktop: stepper complet avec connecteurs */}
      <ol className="hidden items-start gap-2 md:flex">
        {steps.map((s, idx) => {
          const isActive = s.id === current;
          const isDone = s.id < current;
          const isFuture = s.id > current;
          const isLast = idx === steps.length - 1;
          return (
            <li key={s.id} className="flex flex-1 items-start gap-3">
              <div className="flex flex-col items-center gap-2">
                <span
                  aria-current={isActive ? 'step' : undefined}
                  className={cn(
                    'relative flex h-11 w-11 items-center justify-center rounded-full text-sm font-bold transition-all duration-300 ease-pssfp-out-expo',
                    isDone && 'bg-emerald-500 text-white shadow-pssfp-soft',
                    isActive && 'bg-[#4A2E67] text-white shadow-pssfp-glow-or',
                    isFuture && 'border-2 border-[#F4EFFA] bg-white text-gray-500',
                  )}
                >
                  {isActive && !reduceMotion && (
                    <span
                      aria-hidden="true"
                      className="absolute inset-0 rounded-full animate-pssfp-pulse-prune"
                    />
                  )}
                  {isDone ? <Check size={18} /> : <span className="relative">{s.id}</span>}
                </span>
                <span
                  className={cn(
                    'text-center text-xs font-medium leading-tight transition-colors duration-200',
                    isActive && 'text-[#4A2E67]',
                    isDone && 'text-emerald-700',
                    isFuture && 'text-gray-500',
                  )}
                >
                  {s.label}
                </span>
              </div>
              {!isLast && (
                <div className="relative mt-5 h-0.5 flex-1 overflow-hidden rounded-full bg-[#F4EFFA]">
                  <motion.div
                    aria-hidden="true"
                    className={cn(
                      'h-full rounded-full',
                      isDone ? 'bg-emerald-500' : 'bg-[#4A2E67]',
                    )}
                    initial={false}
                    animate={{ width: isDone ? '100%' : isActive ? '50%' : '0%' }}
                    transition={
                      reduceMotion ? { duration: 0 } : { duration: 0.5, ease: [0.16, 1, 0.3, 1] }
                    }
                  />
                </div>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
