'use client';

import { useState, useTransition } from 'react';
import {
  requestOtpAction,
  resetPinAction,
  verifyOtpAction,
} from '@/app/forgot-pin/actions';
import { PinInput } from '@/components/PinInput';
import { isValidE164 } from '@/lib/format/phone';
import { validateCandidatePin } from '@/lib/validation/pinValidation';

const STEP_LABELS = [
  '1. Numéro de téléphone',
  '2. Code reçu par SMS',
  '3. Nouveau PIN',
];

const PIN_REASON_FR: Record<string, string> = {
  'pin.invalid_format': 'Le PIN doit comporter 6 chiffres.',
  'pin.blacklisted': 'Ce PIN est trop courant. Choisissez-en un autre.',
  'pin.matches_phone_suffix': 'Ce PIN reproduit la fin de votre numéro de téléphone.',
  'pin.matches_date_of_birth': 'Ce PIN reproduit votre date de naissance.',
};

export function ForgotPinWizard(): JSX.Element {
  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [newPin, setNewPin] = useState('');
  const [newPinConfirm, setNewPinConfirm] = useState('');
  const [serverError, setServerError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const phoneValid = isValidE164(phone);
  const codeValid = /^\d{6}$/.test(code);
  const pinResult = validateCandidatePin(newPin, phone, null);

  const submitPhone = (): void => {
    if (!phoneValid) return;
    setServerError(null);
    startTransition(async () => {
      const r = await requestOtpAction(phone);
      if (!r.ok) {
        setServerError(r.message ?? 'Erreur réseau.');
        return;
      }
      setStep(2);
    });
  };

  const submitCode = (): void => {
    if (!codeValid) return;
    setServerError(null);
    startTransition(async () => {
      const r = await verifyOtpAction(phone, code);
      if (!r.ok) {
        setServerError(r.message ?? 'Erreur.');
        return;
      }
      setStep(3);
    });
  };

  const submitNewPin = (): void => {
    if (!pinResult.ok || newPin !== newPinConfirm) return;
    setServerError(null);
    startTransition(async () => {
      const r = await resetPinAction(newPin, newPinConfirm);
      // resetPinAction redirige côté serveur en cas de succès ; on ne reçoit
      // un retour ici qu'en cas d'échec.
      if (r && !r.ok) {
        setServerError(r.message ?? 'Erreur lors du reset.');
      }
    });
  };

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm md:p-8">
      <ol className="mb-6 flex items-center gap-2 text-sm" aria-label="Progression">
        {STEP_LABELS.map((label, idx) => {
          const id = idx + 1;
          const active = step === id;
          const done = id < step;
          return (
            <li key={id} className="flex flex-1 items-center gap-2">
              <span
                aria-current={active ? 'step' : undefined}
                className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold ${
                  done
                    ? 'bg-emerald-100 text-emerald-700'
                    : active
                    ? 'bg-[#4A2E67] text-white'
                    : 'bg-gray-100 text-gray-400'
                }`}
              >
                {done ? '✓' : id}
              </span>
              <span className={`hidden md:inline ${active ? 'text-[#4A2E67] font-medium' : 'text-gray-500'}`}>
                {label}
              </span>
              {idx < STEP_LABELS.length - 1 && (
                <span aria-hidden className="ml-2 hidden h-px flex-1 bg-gray-200 md:inline-block" />
              )}
            </li>
          );
        })}
      </ol>

      {step === 1 && (
        <div className="space-y-5" data-testid="forgot-pin-step-1">
          <h2 className="font-heading text-lg font-semibold text-[#4A2E67]">
            Saisissez votre numéro de téléphone
          </h2>
          <p className="text-sm text-[#666]">
            Nous vous enverrons un code de réinitialisation par SMS au numéro enregistré
            lors de votre inscription.
          </p>
          <input
            data-testid="forgot-phone"
            type="tel"
            inputMode="tel"
            placeholder="+237691234567"
            value={phone}
            onChange={(e) => setPhone(e.target.value.replace(/[^\d+]/g, ''))}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
          {serverError && (
            <div role="alert" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
              {serverError}
            </div>
          )}
          <button
            type="button"
            data-testid="forgot-submit-phone"
            disabled={!phoneValid || pending}
            onClick={submitPhone}
            className="h-11 w-full rounded-md bg-[#4A2E67] text-sm font-medium text-white hover:bg-[#5C3A7E] disabled:cursor-not-allowed disabled:bg-gray-300"
          >
            {pending ? 'Envoi…' : 'Recevoir le code'}
          </button>
        </div>
      )}

      {step === 2 && (
        <div className="space-y-5" data-testid="forgot-pin-step-2">
          <h2 className="font-heading text-lg font-semibold text-[#4A2E67]">
            Saisissez le code reçu par SMS
          </h2>
          <p className="text-sm text-[#666]">
            Le code à 6 chiffres a été envoyé au {phone}. Il est valable 10 minutes.
          </p>
          <PinInput ariaLabel="Code SMS" testId="forgot-otp-input" value={code} onChange={setCode} />
          {serverError && (
            <div role="alert" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
              {serverError}
            </div>
          )}
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => {
                setStep(1);
                setServerError(null);
                setCode('');
              }}
              className="rounded-md border border-gray-300 px-4 py-2 text-sm text-[#333]"
            >
              Précédent
            </button>
            <button
              type="button"
              data-testid="forgot-submit-otp"
              disabled={!codeValid || pending}
              onClick={submitCode}
              className="h-11 flex-1 rounded-md bg-[#4A2E67] text-sm font-medium text-white hover:bg-[#5C3A7E] disabled:cursor-not-allowed disabled:bg-gray-300"
            >
              {pending ? 'Vérification…' : 'Valider le code'}
            </button>
          </div>
        </div>
      )}

      {step === 3 && (
        <div className="space-y-5" data-testid="forgot-pin-step-3">
          <h2 className="font-heading text-lg font-semibold text-[#4A2E67]">
            Choisissez votre nouveau PIN
          </h2>
          <p className="text-sm text-[#666]">
            Six chiffres mémorisables, mais évitez les suites évidentes ou votre date de naissance.
          </p>
          <div>
            <span className="mb-1 block text-sm font-medium text-[#333]">Nouveau PIN</span>
            <PinInput ariaLabel="Nouveau PIN" testId="forgot-new-pin" value={newPin} onChange={setNewPin} />
            {newPin.length === 6 && !pinResult.ok && (
              <ul role="alert" className="mt-2 space-y-1 text-xs text-red-600">
                {pinResult.reasons.map((r) => (
                  <li key={r}>{PIN_REASON_FR[r] ?? r}</li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <span className="mb-1 block text-sm font-medium text-[#333]">Confirmation</span>
            <PinInput
              ariaLabel="Confirmation du nouveau PIN"
              testId="forgot-new-pin-confirm"
              noAutoFocus
              value={newPinConfirm}
              onChange={setNewPinConfirm}
            />
            {newPinConfirm.length === 6 && newPin !== newPinConfirm && (
              <span role="alert" className="mt-1 block text-xs text-red-600">
                La confirmation ne correspond pas.
              </span>
            )}
          </div>
          {serverError && (
            <div role="alert" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
              {serverError}
            </div>
          )}
          <button
            type="button"
            data-testid="forgot-submit-reset"
            disabled={!pinResult.ok || newPin !== newPinConfirm || pending}
            onClick={submitNewPin}
            className="h-11 w-full rounded-md bg-[#4A2E67] text-sm font-medium text-white hover:bg-[#5C3A7E] disabled:cursor-not-allowed disabled:bg-gray-300"
          >
            {pending ? 'Mise à jour…' : 'Définir mon nouveau PIN'}
          </button>
        </div>
      )}
    </div>
  );
}
