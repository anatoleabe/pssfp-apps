'use client';

import { useEffect, useMemo, useRef, useState, useTransition } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import type { Pays, Specialite } from '@/lib/api/types';
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
const STEPS = [
  { id: 1, label: 'Identité' },
  { id: 2, label: 'Coordonnées' },
  { id: 3, label: 'Diplôme' },
  { id: 4, label: 'Engagement' },
];

export interface WizardContainerProps {
  pays: Pays[];
  specialites: Specialite[];
  /** Server Action injectée — appelée à la soumission finale. */
  submitAction: (data: WizardData) => Promise<WizardServerActionResult>;
}

export function WizardContainer({ pays, specialites, submitAction }: WizardContainerProps): JSX.Element {
  const router = useRouter();
  const [step, setStep] = useState(1);
  const [data, setData] = useState<WizardData>(() => readSession() ?? initialWizardData);
  const [errors, setErrors] = useState<Partial<Record<keyof WizardData, string>>>({});
  const [serverErrors, setServerErrors] = useState<Record<string, string> | undefined>();
  const [serverCta, setServerCta] = useState<WizardServerActionResult['cta'] | null>(null);
  const [pending, startTransition] = useTransition();
  const inactivityTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Persistance sessionStorage à chaque update.
  useEffect(() => {
    try {
      sessionStorage.setItem(SESSION_KEY, JSON.stringify(data));
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
  useEffect(() => {
    const reset = (): void => {
      if (inactivityTimer.current) {
        clearTimeout(inactivityTimer.current);
      }
      inactivityTimer.current = setTimeout(() => {
        try {
          sessionStorage.removeItem(SESSION_KEY);
        } catch {
          // ignore
        }
        router.push('/?inactivity_timeout=1');
      }, INACTIVITY_TIMEOUT_MS);
    };
    const events: Array<keyof DocumentEventMap> = ['mousedown', 'keydown', 'scroll', 'touchstart'];
    events.forEach((evt) => document.addEventListener(evt, reset));
    reset();
    return () => {
      if (inactivityTimer.current) {
        clearTimeout(inactivityTimer.current);
      }
      events.forEach((evt) => document.removeEventListener(evt, reset));
    };
  }, [router]);

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
    return data.cgu;
  }, [data]);

  const goNext = (): void => {
    if (!validateStep(step)) {
      return;
    }
    setStep((s) => Math.min(4, s + 1));
  };

  const goPrev = (): void => {
    setErrors({});
    setStep((s) => Math.max(1, s - 1));
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
    if (!validateStep(4) || !isStep4Strict) {
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
      // Si erreur sur phone_e164 → revenir à l'étape 2 pour correction.
      if (result.errors?.phone_e164) {
        setStep(2);
      }
    });
  };

  const mergedErrors = { ...errors, ...(serverErrors ?? {}) } as Partial<Record<keyof WizardData, string>>;

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm md:p-8">
      <WizardStepper current={step} steps={STEPS} />

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
      {step === 3 && <WizardStep3Diplome data={data} errors={mergedErrors} onChange={patch} />}
      {step === 4 && (
        <WizardStep4Engagement
          data={data}
          errors={mergedErrors}
          cta={serverCta ?? null}
          onChange={patch}
        />
      )}

      <div className="mt-8 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-6">
        <button
          type="button"
          onClick={cancel}
          className="text-sm text-gray-500 underline hover:text-[#6B2FA0]"
        >
          Annuler et retourner à l'accueil
        </button>

        <div className="flex items-center gap-3">
          {step > 1 && (
            <button
              type="button"
              onClick={goPrev}
              className="rounded-md border border-gray-300 px-4 py-2 text-sm text-[#333333] hover:border-[#6B2FA0] hover:text-[#6B2FA0]"
            >
              Précédent
            </button>
          )}
          {step < 4 && (
            <button
              type="button"
              onClick={goNext}
              data-testid="wizard-next"
              className="rounded-md bg-[#6B2FA0] px-5 py-2 text-sm font-medium text-white hover:bg-[#9B59B6]"
            >
              Suivant
            </button>
          )}
          {step === 4 && (
            <button
              type="button"
              data-testid="wizard-submit"
              disabled={pending || !isStep4Strict}
              onClick={submit}
              className="rounded-md bg-[#6B2FA0] px-5 py-2 text-sm font-medium text-white hover:bg-[#9B59B6] disabled:cursor-not-allowed disabled:bg-gray-300"
            >
              {pending ? 'Envoi…' : 'Soumettre ma candidature'}
            </button>
          )}
        </div>
      </div>

      <p className="mt-4 text-xs text-gray-400">
        Vos données sont enregistrées localement (session navigateur) et seront effacées
        automatiquement après 30 minutes d'inactivité ou à la fermeture de l'onglet.{' '}
        <Link href="/" className="underline">
          Retour à l'accueil
        </Link>
      </p>
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
