'use client';

import { useEffect, useMemo, useRef, useState, useTransition } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { Diplome, Pays, Specialite, UniversitePays } from '@/lib/api/types';
import { isTurnstileEnabled } from '@/components/TurnstileWidget';
import { isValidEngagement } from '@/lib/format/engagement';
import { isValidE164 } from '@/lib/format/phone';
import { validateCandidatePin } from '@/lib/validation/pinValidation';
import {
  step1Schema,
  step2Schema,
  step3Schema,
  step4Schema,
} from '@/lib/validation/schemas';
import { initialWizardData, type WizardData, type WizardServerActionResult } from './types';
import { WizardStep1Identite } from './WizardStep1Identite';
import { WizardStep2Coordonnees } from './WizardStep2Coordonnees';
import { WizardStep3Diplome } from './WizardStep3Diplome';
import { WizardStep4Engagement } from './WizardStep4Engagement';
import { WizardStepper } from './WizardStepper';

const SESSION_KEY = 'pssfp.inscription.wizard.v1';
const INACTIVITY_TIMEOUT_MS = 30 * 60 * 1000; // 30 minutes
// Avertissement WCAG 2.2.1 : prévenir avant l'expiration, avec possibilité
// de prolonger. La modale apparaît 2 minutes avant l'effacement.
const INACTIVITY_WARNING_MS = INACTIVITY_TIMEOUT_MS - 2 * 60 * 1000;

export interface WizardContainerProps {
  pays: Pays[];
  specialites: Specialite[];
  diplomes: Diplome[];
  universites: UniversitePays[];
  /** Server Action injectée — appelée à la soumission finale. */
  submitAction: (data: WizardData) => Promise<WizardServerActionResult>;
}

export function WizardContainer({
  pays,
  specialites,
  diplomes,
  universites,
  submitAction,
}: WizardContainerProps): JSX.Element {
  const t = useTranslations('wizard');
  const router = useRouter();
  const [step, setStep] = useState(1);
  const [data, setData] = useState<WizardData>(() => readSession() ?? initialWizardData);
  const [errors, setErrors] = useState<Partial<Record<keyof WizardData, string>>>({});
  const [serverErrors, setServerErrors] = useState<Record<string, string> | undefined>();
  const [serverCta, setServerCta] = useState<WizardServerActionResult['cta'] | null>(null);
  const [turnstileResetKey, setTurnstileResetKey] = useState(0);
  const [timeoutWarning, setTimeoutWarning] = useState(false);
  const [pending, startTransition] = useTransition();
  const inactivityTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const warningTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const resetTimersRef = useRef<(() => void) | null>(null);
  const stepPanelRef = useRef<HTMLDivElement | null>(null);
  const continueButtonRef = useRef<HTMLButtonElement | null>(null);

  const steps = useMemo(
    () => [
      { id: 1, label: t('steps.identite') },
      { id: 2, label: t('steps.coordonnees') },
      { id: 3, label: t('steps.diplome') },
      { id: 4, label: t('steps.engagement') },
    ],
    [t],
  );

  // Persistance sessionStorage à chaque update. Exclusions volontaires :
  // - turnstile_token : single-use, déjà invalide côté Cloudflare s'il est restauré ;
  // - pin / pin_confirmation : credential, jamais écrit sur disque (PC public —
  //   sessionStorage est en clair et beforeunload n'est pas fiable sur mobile).
  useEffect(() => {
    try {
      sessionStorage.setItem(
        SESSION_KEY,
        JSON.stringify({ ...data, turnstile_token: '', pin: '', pin_confirmation: '' }),
      );
    } catch {
      // noop : sessionStorage indisponible (mode privé strict)
    }
  }, [data]);

  // beforeunload : effacer la sessionStorage si l'utilisateur ferme l'onglet.
  // Cf. ajout 1 PR E (sécurité PC public).
  useEffect(() => {
    const onBeforeUnload = (): void => {
      try {
        sessionStorage.removeItem(SESSION_KEY);
      } catch {
        // ignore
      }
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, []);

  // Timer d'inactivité 30 min — efface la sessionStorage et redirige vers /
  // (cf. ajout 3 PR E, alignement banque mobile MTN MoMo / Orange Money).
  // Une modale d'avertissement (WCAG 2.2.1) s'affiche 2 min avant l'expiration ;
  // toute activité (clic, touche, scroll) remet les compteurs à zéro.
  useEffect(() => {
    const clearTimers = (): void => {
      if (inactivityTimer.current) {
        clearTimeout(inactivityTimer.current);
      }
      if (warningTimer.current) {
        clearTimeout(warningTimer.current);
      }
    };
    const reset = (): void => {
      clearTimers();
      setTimeoutWarning(false);
      warningTimer.current = setTimeout(() => setTimeoutWarning(true), INACTIVITY_WARNING_MS);
      inactivityTimer.current = setTimeout(() => {
        try {
          sessionStorage.removeItem(SESSION_KEY);
        } catch {
          // ignore
        }
        router.push('/?inactivity_timeout=1');
      }, INACTIVITY_TIMEOUT_MS);
    };
    resetTimersRef.current = reset;
    const events: Array<keyof DocumentEventMap> = ['mousedown', 'keydown', 'scroll', 'touchstart'];
    events.forEach((evt) => document.addEventListener(evt, reset));
    reset();
    return () => {
      clearTimers();
      resetTimersRef.current = null;
      events.forEach((evt) => document.removeEventListener(evt, reset));
    };
  }, [router]);

  // La modale d'avertissement prend le focus à l'ouverture (annonce lecteur d'écran).
  useEffect(() => {
    if (timeoutWarning) {
      continueButtonRef.current?.focus();
    }
  }, [timeoutWarning]);

  const patch = (delta: Partial<WizardData>): void => {
    setData((prev) => ({ ...prev, ...delta }));
    // Efface les erreurs des champs touchés pour éviter le bruit.
    setErrors((prev) => {
      const next = { ...prev };
      Object.keys(delta).forEach((k) => delete next[k as keyof WizardData]);
      return next;
    });
  };

  const validateStep = (n: number): boolean => {
    const schema =
      n === 1 ? step1Schema : n === 2 ? step2Schema : n === 3 ? step3Schema : step4Schema;
    const result = schema.safeParse(data);
    if (result.success) {
      setErrors({});
      return true;
    }
    const next: Partial<Record<keyof WizardData, string>> = {};
    for (const issue of result.error.issues) {
      const key = issue.path[0] as keyof WizardData;
      if (!next[key]) {
        next[key] = issue.message;
      }
    }
    setErrors(next);
    requestAnimationFrame(() => {
      const firstInvalid = stepPanelRef.current?.querySelector<HTMLElement>(
        '[data-field-error="true"] input, [data-field-error="true"] select, [data-field-error="true"] button, [aria-invalid="true"]',
      );
      firstInvalid?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      firstInvalid?.focus({ preventScroll: true });
    });
    return false;
  };

  // Validation supplémentaire au step 4 (PIN + engagement) au-delà du schéma zod.
  const isStep4Strict = useMemo((): boolean => {
    if (!validateCandidatePin(data.pin, data.phone_e164, data.date_naissance || null).ok) {
      return false;
    }
    if (data.pin !== data.pin_confirmation) {
      return false;
    }
    if (!isValidEngagement(data.engagement_nom, data.prenom, data.nom)) {
      return false;
    }
    if (!isValidE164(data.phone_e164)) {
      return false;
    }
    if (isTurnstileEnabled() && data.turnstile_token.length === 0) {
      return false;
    }
    return data.cgu;
  }, [data]);

  // Focus programmé sur le panneau de l'étape après navigation (WCAG 2.4.3) :
  // sans lui, le focus reste sur le bouton « Suivant » et les AT ne perçoivent
  // pas le changement de contenu.
  const focusStepPanel = (): void => {
    requestAnimationFrame(() => stepPanelRef.current?.focus());
  };

  const goNext = (): void => {
    if (!validateStep(step)) {
      return;
    }
    setStep((s) => Math.min(4, s + 1));
    focusStepPanel();
  };

  const goPrev = (): void => {
    setErrors({});
    setStep((s) => Math.max(1, s - 1));
    focusStepPanel();
  };

  const cancel = (): void => {
    try {
      sessionStorage.removeItem(SESSION_KEY);
    } catch {
      // ignore
    }
    router.push('/');
  };

  const submit = (): void => {
    if (!validateStep(4)) {
      return;
    }
    if (!isStep4Strict) {
      const strictErrors: Partial<Record<keyof WizardData, string>> = {};
      const pinResult = validateCandidatePin(data.pin, data.phone_e164, data.date_naissance || null);
      if (!pinResult.ok) strictErrors.pin = 'Choisissez un PIN plus sûr en respectant les règles indiquées.';
      if (data.pin !== data.pin_confirmation) strictErrors.pin_confirmation = 'La confirmation du PIN ne correspond pas.';
      if (!isValidEngagement(data.engagement_nom, data.prenom, data.nom)) strictErrors.engagement_nom = 'Vous devez certifier l’exactitude des informations.';
      if (isTurnstileEnabled() && data.turnstile_token.length === 0) strictErrors.turnstile_token = 'Validez la vérification anti-robot.';
      setErrors(strictErrors);
      requestAnimationFrame(() => {
        const firstInvalid = stepPanelRef.current?.querySelector<HTMLElement>('[data-field-error="true"] input, [aria-invalid="true"]');
        firstInvalid?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstInvalid?.focus({ preventScroll: true });
      });
      return;
    }
    startTransition(async () => {
      const result = await submitAction(data);
      if (result.ok && result.redirectTo) {
        try {
          sessionStorage.removeItem(SESSION_KEY);
        } catch {
          // ignore
        }
        router.push(result.redirectTo);
        return;
      }
      setServerErrors(result.errors);
      setServerCta(result.cta ?? null);
      // Soumission échouée : le token Turnstile est consommé, on en régénère un.
      setData((prev) => ({ ...prev, turnstile_token: '' }));
      setTurnstileResetKey((k) => k + 1);
      // Si erreur sur phone_e164 → revenir à l'étape 2 pour correction.
      if (result.errors?.phone_e164) {
        setStep(2);
        focusStepPanel();
      }
    });
  };

  const mergedErrors = { ...errors, ...(serverErrors ?? {}) } as Partial<Record<keyof WizardData, string>>;

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm md:p-8">
      <WizardStepper current={step} steps={steps} />

      <div ref={stepPanelRef} tabIndex={-1} className="focus:outline-none">
        {step === 1 && (
          <WizardStep1Identite
            data={data}
            errors={mergedErrors}
            pays={pays}
            specialites={specialites}
            onChange={patch}
          />
        )}
        {step === 2 && (
          <WizardStep2Coordonnees data={data} errors={mergedErrors} pays={pays} onChange={patch} />
        )}
        {step === 3 && (
          <WizardStep3Diplome
            data={data}
            errors={mergedErrors}
            onChange={patch}
            diplomes={diplomes}
            universites={universites}
          />
        )}
        {step === 4 && (
          <WizardStep4Engagement
            data={data}
            errors={mergedErrors}
            cta={serverCta ?? null}
            turnstileResetKey={turnstileResetKey}
            onChange={patch}
            onEditStep={(target) => {
              setStep(target);
              setErrors({});
              focusStepPanel();
            }}
          />
        )}
      </div>

      <div className="mt-8 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-6">
        <button
          type="button"
          onClick={cancel}
          className="text-sm text-gray-500 underline hover:text-[#4A2E67]"
        >
          {t('cancel')}
        </button>

        <div className="flex items-center gap-3">
          {step > 1 && (
            <button
              type="button"
              onClick={goPrev}
              className="rounded-md border border-gray-300 px-4 py-2 text-sm text-[#333333] hover:border-[#4A2E67] hover:text-[#4A2E67]"
            >
              {t('prev')}
            </button>
          )}
          {step < 4 && (
            <button
              type="button"
              onClick={goNext}
              data-testid="wizard-next"
              className="rounded-md bg-[#4A2E67] px-5 py-2 text-sm font-medium text-white hover:bg-[#5C3A7E]"
            >
              {t('next')}
            </button>
          )}
          {step === 4 && (
            <button
              type="button"
              data-testid="wizard-submit"
              disabled={pending}
              onClick={submit}
              className="rounded-md bg-[#4A2E67] px-5 py-2 text-sm font-medium text-white hover:bg-[#5C3A7E] disabled:cursor-not-allowed disabled:bg-gray-300"
            >
              {pending ? t('submitting') : t('submit')}
            </button>
          )}
        </div>
      </div>

      <p className="mt-4 text-xs text-gray-500">
        {t('retentionNotice')}{' '}
        <Link href="/" className="underline">
          {t('backHome')}
        </Link>
      </p>

      {timeoutWarning && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
          data-testid="wizard-timeout-warning"
        >
          <div
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="timeout-warning-title"
            aria-describedby="timeout-warning-body"
            className="w-full max-w-md rounded-lg bg-white p-6 shadow-lg"
          >
            <h2 id="timeout-warning-title" className="font-heading text-lg font-bold text-[#4A2E67]">
              {t('timeout.title')}
            </h2>
            <p id="timeout-warning-body" className="mt-2 text-sm text-[#333333]">
              {t('timeout.body')}
            </p>
            <button
              ref={continueButtonRef}
              type="button"
              onClick={() => resetTimersRef.current?.()}
              className="mt-5 h-11 w-full rounded-md bg-[#4A2E67] text-sm font-medium text-white hover:bg-[#5C3A7E]"
            >
              {t('timeout.continue')}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function readSession(): WizardData | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = sessionStorage.getItem(SESSION_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<WizardData>;
    return { ...initialWizardData, ...parsed };
  } catch {
    return null;
  }
}
