'use client';

import { useEffect, useRef, useState, useTransition } from 'react';
import { useTranslations } from 'next-intl';
import {
  requestOtpAction,
  resetPinAction,
  verifyOtpAction,
  type SimpleResult,
} from '@/app/forgot-pin/actions';
import { PinInput } from '@/components/PinInput';
import { TurnstileWidget, isTurnstileEnabled } from '@/components/TurnstileWidget';
import { isValidE164 } from '@/lib/format/phone';
import { validateCandidatePin } from '@/lib/validation/pinValidation';

/** Codes de refus émis par validateCandidatePin — mappés sur pinReasons.* */
const KNOWN_PIN_REASONS = new Set([
  'pin.invalid_format',
  'pin.blacklisted',
  'pin.matches_phone_suffix',
  'pin.matches_date_of_birth',
]);

export function ForgotPinWizard(): JSX.Element {
  const t = useTranslations('forgotPin');
  const tErr = useTranslations('errors');
  const tPin = useTranslations('pinReasons');
  const tTurnstile = useTranslations('turnstile');
  const tLogin = useTranslations('login');

  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [newPin, setNewPin] = useState('');
  const [newPinConfirm, setNewPinConfirm] = useState('');
  const [turnstileToken, setTurnstileToken] = useState('');
  const [turnstileResetKey, setTurnstileResetKey] = useState(0);
  const [serverError, setServerError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();
  const stepHeadingRef = useRef<HTMLHeadingElement | null>(null);
  const mountedRef = useRef(false);

  const stepLabels = [t('steps.phone'), t('steps.code'), t('steps.newPin')];

  // Priorité au code d'erreur structuré (traduisible) ; le message serveur ne
  // sert que de fallback technique (cf. revue i18n LOT A).
  const localizedError = (r: SimpleResult, kindMap: Record<string, string>): string => {
    const mapped = r.errorKind ? kindMap[r.errorKind] : undefined;
    return mapped ?? r.message ?? tErr('generic');
  };

  const phoneValid = isValidE164(phone);
  const phoneInvalidVisible = phone.length > 3 && !phoneValid;
  const codeValid = /^\d{6}$/.test(code);
  const pinResult = validateCandidatePin(newPin, phone, null);
  const turnstileOk = !isTurnstileEnabled() || turnstileToken.length > 0;

  const pinReasonLabel = (reason: string): string =>
    KNOWN_PIN_REASONS.has(reason) ? tPin(reason.replace('pin.', '')) : reason;

  // Focus programmé sur le titre de l'étape après transition (WCAG 2.4.3).
  useEffect(() => {
    if (!mountedRef.current) {
      mountedRef.current = true;
      return;
    }
    stepHeadingRef.current?.focus();
  }, [step]);

  const submitPhone = (): void => {
    if (!phoneValid || !turnstileOk) return;
    setServerError(null);
    startTransition(async () => {
      const r = await requestOtpAction(phone, turnstileToken);
      if (!r.ok) {
        setServerError(
          localizedError(r, { invalid: tErr('turnstileFailed'), network: tErr('network') }),
        );
        // Token consommé côté siteverify : forcer un nouveau challenge.
        setTurnstileToken('');
        setTurnstileResetKey((k) => k + 1);
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
        setServerError(
          localizedError(r, {
            invalid: tErr('otpInvalid'),
            expired: tErr('otpExpired'),
            already_used: tErr('otpUsed'),
            throttled: tErr('throttled'),
            network: tErr('network'),
          }),
        );
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
        setServerError(
          localizedError(r, {
            expired: tErr('resetSessionExpired'),
            invalid: tErr('pinRejected'),
            network: tErr('network'),
          }),
        );
      }
    });
  };

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm md:p-8">
      <ol className="mb-6 flex items-center gap-2 text-sm" aria-label={t('progressAria')}>
        {stepLabels.map((label, idx) => {
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
                    : 'bg-gray-100 text-gray-600'
                }`}
              >
                {done ? '✓' : id}
              </span>
              <span className={`hidden md:inline ${active ? 'text-[#4A2E67] font-medium' : 'text-gray-500'}`}>
                {label}
              </span>
              {idx < stepLabels.length - 1 && (
                <span aria-hidden className="ml-2 hidden h-px flex-1 bg-gray-200 md:inline-block" />
              )}
            </li>
          );
        })}
      </ol>

      {step === 1 && (
        <div className="space-y-5" data-testid="forgot-pin-step-1">
          <h2
            ref={stepHeadingRef}
            tabIndex={-1}
            className="font-heading text-lg font-semibold text-[#4A2E67] focus:outline-none"
          >
            {t('step1Title')}
          </h2>
          <p className="text-sm text-[#666]">{t('step1Body')}</p>
          <div>
            <label htmlFor="forgot-phone-input" className="block text-sm font-medium text-[#333333]">
              {t('phoneLabel')}
            </label>
            <input
              id="forgot-phone-input"
              data-testid="forgot-phone"
              type="tel"
              inputMode="tel"
              placeholder={tLogin('phonePlaceholder')}
              value={phone}
              onChange={(e) => setPhone(e.target.value.replace(/[^\d+]/g, ''))}
              aria-invalid={phoneInvalidVisible}
              aria-describedby={phoneInvalidVisible ? 'forgot-phone-help forgot-phone-error' : 'forgot-phone-help'}
              className="mt-1 h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
            />
            <p id="forgot-phone-help" className="mt-1 text-xs text-[#595959]">
              {t('phoneHelp')}
            </p>
            {phoneInvalidVisible && (
              <p id="forgot-phone-error" className="mt-1 text-xs text-red-600">
                {t('phoneInvalid')}
              </p>
            )}
          </div>
          {isTurnstileEnabled() && (
            <div className="space-y-2">
              <span className="block text-sm font-medium text-[#333333]">{tTurnstile('heading')}</span>
              <TurnstileWidget
                action="candidat-forgot-pin"
                resetKey={turnstileResetKey}
                onVerify={setTurnstileToken}
                onExpire={() => setTurnstileToken('')}
              />
            </div>
          )}
          {serverError && (
            <div role="alert" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
              {serverError}
            </div>
          )}
          <button
            type="button"
            data-testid="forgot-submit-phone"
            disabled={!phoneValid || !turnstileOk || pending}
            onClick={submitPhone}
            className="h-11 w-full rounded-md bg-[#4A2E67] text-sm font-medium text-white hover:bg-[#5C3A7E] disabled:cursor-not-allowed disabled:bg-gray-300"
          >
            {pending ? t('sending') : t('sendCode')}
          </button>
        </div>
      )}

      {step === 2 && (
        <div className="space-y-5" data-testid="forgot-pin-step-2">
          <h2
            ref={stepHeadingRef}
            tabIndex={-1}
            className="font-heading text-lg font-semibold text-[#4A2E67] focus:outline-none"
          >
            {t('step2Title')}
          </h2>
          <p className="text-sm text-[#666]">{t('step2Body', { phone })}</p>
          <PinInput ariaLabel={t('otpAria')} testId="forgot-otp-input" noAutoFocus value={code} onChange={setCode} />
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
              {t('prev')}
            </button>
            <button
              type="button"
              data-testid="forgot-submit-otp"
              disabled={!codeValid || pending}
              onClick={submitCode}
              className="h-11 flex-1 rounded-md bg-[#4A2E67] text-sm font-medium text-white hover:bg-[#5C3A7E] disabled:cursor-not-allowed disabled:bg-gray-300"
            >
              {pending ? t('verifying') : t('verifyCode')}
            </button>
          </div>
        </div>
      )}

      {step === 3 && (
        <div className="space-y-5" data-testid="forgot-pin-step-3">
          <h2
            ref={stepHeadingRef}
            tabIndex={-1}
            className="font-heading text-lg font-semibold text-[#4A2E67] focus:outline-none"
          >
            {t('step3Title')}
          </h2>
          <p className="text-sm text-[#666]">{t('step3Body')}</p>
          <div>
            <span className="mb-1 block text-sm font-medium text-[#333]">{t('newPinLabel')}</span>
            <PinInput
              ariaLabel={t('newPinAria')}
              testId="forgot-new-pin"
              noAutoFocus
              describedBy={newPin.length === 6 && !pinResult.ok ? 'forgot-new-pin-errors' : undefined}
              value={newPin}
              onChange={setNewPin}
            />
            {newPin.length === 6 && !pinResult.ok && (
              <ul id="forgot-new-pin-errors" role="alert" className="mt-2 space-y-1 text-xs text-red-600">
                {pinResult.reasons.map((r) => (
                  <li key={r}>{pinReasonLabel(r)}</li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <span className="mb-1 block text-sm font-medium text-[#333]">{t('confirmLabel')}</span>
            <PinInput
              ariaLabel={t('confirmAria')}
              testId="forgot-new-pin-confirm"
              noAutoFocus
              describedBy={
                newPinConfirm.length === 6 && newPin !== newPinConfirm
                  ? 'forgot-new-pin-confirm-error'
                  : undefined
              }
              value={newPinConfirm}
              onChange={setNewPinConfirm}
            />
            {newPinConfirm.length === 6 && newPin !== newPinConfirm && (
              <span id="forgot-new-pin-confirm-error" role="alert" className="mt-1 block text-xs text-red-600">
                {t('confirmMismatch')}
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
            {pending ? t('updating') : t('setPin')}
          </button>
        </div>
      )}
    </div>
  );
}
