'use client';

interface WizardStepperProps {
  current: number; // 1..4
  steps: Array<{ id: number; label: string }>;
}

export function WizardStepper({ current, steps }: WizardStepperProps): JSX.Element {
  return (
    <ol className="mb-8 flex items-center gap-2 text-sm" aria-label="Progression du formulaire">
      {steps.map((s, idx) => {
        const isActive = s.id === current;
        const isDone = s.id < current;
        return (
          <li key={s.id} className="flex flex-1 items-center gap-2">
            <span
              aria-current={isActive ? 'step' : undefined}
              className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold ${
                isDone
                  ? 'bg-emerald-100 text-emerald-700'
                  : isActive
                  ? 'bg-[#6B2FA0] text-white'
                  : 'bg-gray-100 text-gray-400'
              }`}
            >
              {isDone ? '✓' : s.id}
            </span>
            <span className={`hidden md:inline ${isActive ? 'text-[#6B2FA0] font-medium' : 'text-gray-500'}`}>
              {s.label}
            </span>
            {idx < steps.length - 1 && (
              <span aria-hidden className="ml-2 hidden h-px flex-1 bg-gray-200 md:inline-block" />
            )}
          </li>
        );
      })}
    </ol>
  );
}
