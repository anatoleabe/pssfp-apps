'use client';

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { Eye, EyeOff } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { PinInput } from '@/components/PinInput';
import { TurnstileWidget, isTurnstileEnabled } from '@/components/TurnstileWidget';
import { isValidEngagement } from '@/lib/format/engagement';
import { validateCandidatePin } from '@/lib/validation/pinValidation';
import type { WizardData } from './types';

/** Codes de refus émis par validateCandidatePin — mappés sur pinReasons.* */
const KNOWN_PIN_REASONS = new Set([
  'pin.invalid_format',
  'pin.blacklisted',
  'pin.matches_phone_suffix',
  'pin.matches_date_of_birth',
]);

export interface WizardStep4Props {
  data: WizardData;
  errors: Partial<Record<keyof WizardData, string>>;
  cta?: { label: string; href: string } | null;
  turnstileResetKey?: number;
  onChange: (patch: Partial<WizardData>) => void;
}

export function WizardStep4Engagement({
  data,
  errors,
  cta,
  turnstileResetKey,
  onChange,
}: WizardStep4Props): JSX.Element {
  const t = useTranslations('wizard.step4');
  const tPin = useTranslations('pinReasons');
  const tTurnstile = useTranslations('turnstile');
  const [showPin, setShowPin] = useState(false);
  const fullName = `${data.prenom} ${data.nom}`.trim();

  const pinReasonLabel = (reason: string): string =>
    KNOWN_PIN_REASONS.has(reason) ? tPin(reason.replace('pin.', '')) : reason;

  const engagementOk = useMemo(
    () => isValidEngagement(data.engagement_nom, data.prenom, data.nom),
    [data.engagement_nom, data.prenom, data.nom],
  );

  const pinResult = useMemo(
    () => validateCandidatePin(data.pin, data.phone_e164, data.date_naissance || null),
    [data.pin, data.phone_e164, data.date_naissance],
  );

  const engagementError =
    errors.engagement_nom ??
    (data.engagement_nom && !engagementOk ? t('signatureMismatch') : undefined);

  const showPinReasons = data.pin.length === 6 && !pinResult.ok;
  const showPinConfirmMismatch =
    data.pin_confirmation.length === 6 && data.pin !== data.pin_confirmation;

  return (
    <div className="space-y-6" data-testid="wizard-step-4">
      <h2 className="font-heading text-xl font-bold text-[#4A2E67]">{t('title')}</h2>

      <section className="rounded-md border border-[#D4AF6A]/40 bg-[#FFFBEA] p-4 text-sm text-[#666]">
        <p>{t('photoNotice')}</p>
      </section>

      <div data-field-error={Boolean(engagementError)}>
        <p className="text-sm text-[#333333]">Nom du candidat : <strong>{fullName}</strong></p>
        <label className="mt-3 flex items-start gap-3 text-sm leading-relaxed text-[#333333]">
          <input
            data-testid="step4-engagement"
            type="checkbox"
            checked={engagementOk}
            onChange={(e) => onChange({ engagement_nom: e.target.checked ? fullName : '' })}
            aria-invalid={Boolean(engagementError)}
            aria-describedby={engagementError ? 'step4-engagement-error' : undefined}
            className="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#4A2E67] focus:ring-[#4A2E67]"
          />
          {t('certificationLabel')}
        </label>
        {engagementError && <span id="step4-engagement-error" role="alert" className="mt-1 block text-xs text-red-700">{engagementError}</span>}
      </div>

      <Field label={t('pinLabel')} error={errors.pin} errorId="step4-pin-schema-error">
        <div className="flex flex-wrap items-center gap-3">
        <PinInput
          ariaLabel={t('pinAria')}
          testId="step4-pin"
          noAutoFocus
          describedBy={showPinReasons ? 'step4-pin-errors' : undefined}
          value={data.pin}
          onChange={(v) => onChange({ pin: v })}
          masked={!showPin}
        />
        <button
          type="button"
          onClick={() => setShowPin((visible) => !visible)}
          aria-pressed={showPin}
          className="inline-flex h-10 items-center gap-2 rounded-md border border-gray-300 px-3 text-sm text-[#4A2E67] hover:bg-[#F4EFFA] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67]"
        >
          {showPin ? <EyeOff size={16} aria-hidden="true" /> : <Eye size={16} aria-hidden="true" />}
          {showPin ? t('hidePin') : t('showPin')}
        </button>
        </div>
        {showPinReasons && (
          <ul id="step4-pin-errors" className="mt-2 space-y-1 text-xs text-red-600" role="alert">
            {pinResult.reasons.map((r) => (
              <li key={r}>{pinReasonLabel(r)}</li>
            ))}
          </ul>
        )}
      </Field>

      <Field label={t('pinConfirmLabel')} error={errors.pin_confirmation}>
        <PinInput
          ariaLabel={t('pinConfirmAria')}
          testId="step4-pin-confirm"
          noAutoFocus
          describedBy={showPinConfirmMismatch ? 'step4-pin-confirm-error' : undefined}
          value={data.pin_confirmation}
          onChange={(v) => onChange({ pin_confirmation: v })}
          masked={!showPin}
        />
        {showPinConfirmMismatch && (
          <span id="step4-pin-confirm-error" role="alert" className="mt-1 block text-xs text-red-600">
            {t('pinMismatch')}
          </span>
        )}
      </Field>

      <div>
        <div className="flex items-start gap-3 text-sm">
          <input
            id="step4-cgu"
            data-testid="step4-cgu"
            type="checkbox"
            checked={data.cgu}
            onChange={(e) => onChange({ cgu: e.target.checked })}
            aria-invalid={Boolean(errors.cgu)}
            aria-describedby={errors.cgu ? 'step4-cgu-links step4-cgu-error' : 'step4-cgu-links'}
            className="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#4A2E67] focus:ring-[#4A2E67]"
          />
          <label htmlFor="step4-cgu" className="leading-relaxed text-[#333333]">
            {t('cguLabel')}
          </label>
        </div>
        <p id="step4-cgu-links" className="mt-1 pl-7 text-xs text-[#595959]">
          {t('consult')}{' '}
          <Link
            href="/cgu"
            target="_blank"
            rel="noopener noreferrer"
            className="text-[#4A2E67] underline hover:text-[#5C3A7E]"
          >
            {t('cguLink')}
            <span className="sr-only"> {t('newTab')}</span>
          </Link>{' '}
          ·{' '}
          <Link
            href="/confidentialite"
            target="_blank"
            rel="noopener noreferrer"
            className="text-[#4A2E67] underline hover:text-[#5C3A7E]"
          >
            {t('privacyLink')}
            <span className="sr-only"> {t('newTab')}</span>
          </Link>
        </p>
      </div>
      {errors.cgu && (
        <span id="step4-cgu-error" role="alert" className="block text-xs text-red-600">
          {errors.cgu}
        </span>
      )}

      {isTurnstileEnabled() && (
        <div className="space-y-2">
          <span className="block text-sm font-medium text-[#333333]">{tTurnstile('heading')}</span>
          <TurnstileWidget
            action="candidat-register"
            resetKey={turnstileResetKey}
            onVerify={(token) => onChange({ turnstile_token: token })}
            onExpire={() => onChange({ turnstile_token: '' })}
          />
          {errors.turnstile_token && (
            <span role="alert" className="block text-xs text-red-600">
              {errors.turnstile_token}
            </span>
          )}
        </div>
      )}

      {cta && (
        <div className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
          <p className="mb-2">{errors.phone_e164}</p>
          <Link
            href={cta.href}
            className="inline-flex items-center rounded-md bg-[#4A2E67] px-4 py-2 text-sm font-medium text-white hover:bg-[#5C3A7E]"
          >
            {t('loginCta')}
          </Link>
        </div>
      )}
    </div>
  );
}

function Field({
  label,
  htmlFor,
  error,
  errorId,
  children,
}: {
  label: string;
  htmlFor?: string;
  error?: string;
  errorId?: string;
  children: React.ReactNode;
}): JSX.Element {
  const labelClass = 'mb-1 block text-sm font-medium text-[#333333]';
  return (
    <div data-field-error={Boolean(error)}>
      {htmlFor ? (
        <label htmlFor={htmlFor} className={labelClass}>
          {label}
        </label>
      ) : (
        <span className={labelClass}>{label}</span>
      )}
      {children}
      {error && (
        <span id={errorId} role="alert" className="mt-1 block text-xs text-red-600">
          {error}
        </span>
      )}
    </div>
  );
}
