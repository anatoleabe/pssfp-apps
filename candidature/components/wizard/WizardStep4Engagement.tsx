'use client';

import { useMemo } from 'react';
import Link from 'next/link';
import { PinInput } from '@/components/PinInput';
import { isValidEngagement } from '@/lib/format/engagement';
import { validateCandidatePin } from '@/lib/validation/pinValidation';
import type { WizardData } from './types';

const PIN_REASON_FR: Record<string, string> = {
  'pin.invalid_format': 'Le PIN doit comporter exactement 6 chiffres.',
  'pin.blacklisted': 'Ce PIN est trop courant. Choisissez-en un autre.',
  'pin.matches_phone_suffix': 'Ce PIN reproduit la fin de votre numéro de téléphone.',
  'pin.matches_date_of_birth': 'Ce PIN reproduit votre date de naissance.',
};

export interface WizardStep4Props {
  data: WizardData;
  errors: Partial<Record<keyof WizardData, string>>;
  cta?: { label: string; href: string } | null;
  onChange: (patch: Partial<WizardData>) => void;
}

export function WizardStep4Engagement({ data, errors, cta, onChange }: WizardStep4Props): JSX.Element {
  const engagementOk = useMemo(
    () => isValidEngagement(data.engagement_nom, data.prenom, data.nom),
    [data.engagement_nom, data.prenom, data.nom],
  );

  const pinResult = useMemo(
    () => validateCandidatePin(data.pin, data.phone_e164, data.date_naissance || null),
    [data.pin, data.phone_e164, data.date_naissance],
  );

  return (
    <div className="space-y-6" data-testid="wizard-step-4">
      <h2 className="font-heading text-xl font-bold text-[#6B2FA0]">Étape 4 — Engagement &amp; PIN</h2>

      <section className="rounded-md border border-[#C9A227]/40 bg-[#FFFBEA] p-4 text-sm text-[#666]">
        <p>
          La photo d'identité officielle pourra être ajoutée depuis votre dossier candidat
          après la création de votre compte.
        </p>
      </section>

      <Field
        label={`Signature numérique (tapez "${data.prenom} ${data.nom}")`}
        error={errors.engagement_nom ?? (data.engagement_nom && !engagementOk ? 'La signature ne correspond pas à votre prénom + nom.' : undefined)}
      >
        <input
          data-testid="step4-engagement"
          type="text"
          value={data.engagement_nom}
          onChange={(e) => onChange({ engagement_nom: e.target.value })}
          aria-invalid={!engagementOk && data.engagement_nom.length > 0}
          className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#6B2FA0] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0]/30"
        />
      </Field>

      <Field label="PIN à 6 chiffres (votre futur mot de passe)">
        <PinInput
          ariaLabel="PIN à 6 chiffres"
          testId="step4-pin"
          value={data.pin}
          onChange={(v) => onChange({ pin: v })}
        />
        {data.pin.length === 6 && !pinResult.ok && (
          <ul className="mt-2 space-y-1 text-xs text-red-600" role="alert">
            {pinResult.reasons.map((r) => (
              <li key={r}>{PIN_REASON_FR[r] ?? r}</li>
            ))}
          </ul>
        )}
      </Field>

      <Field label="Confirmez le PIN" error={errors.pin_confirmation}>
        <PinInput
          ariaLabel="Confirmation du PIN"
          testId="step4-pin-confirm"
          noAutoFocus
          value={data.pin_confirmation}
          onChange={(v) => onChange({ pin_confirmation: v })}
        />
        {data.pin_confirmation.length === 6 && data.pin !== data.pin_confirmation && (
          <span role="alert" className="mt-1 block text-xs text-red-600">
            La confirmation ne correspond pas au PIN.
          </span>
        )}
      </Field>

      <label className="flex items-start gap-3 text-sm">
        <input
          data-testid="step4-cgu"
          type="checkbox"
          checked={data.cgu}
          onChange={(e) => onChange({ cgu: e.target.checked })}
          className="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#6B2FA0] focus:ring-[#6B2FA0]"
        />
        <span>
          J'accepte les conditions générales d'utilisation et la politique de confidentialité du PSSFP.
        </span>
      </label>
      {errors.cgu && (
        <span role="alert" className="block text-xs text-red-600">
          {errors.cgu}
        </span>
      )}

      {cta && (
        <div className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
          <p className="mb-2">{errors.phone_e164}</p>
          <Link
            href={cta.href}
            className="inline-flex items-center rounded-md bg-[#6B2FA0] px-4 py-2 text-sm font-medium text-white hover:bg-[#9B59B6]"
          >
            {cta.label}
          </Link>
        </div>
      )}
    </div>
  );
}

function Field({
  label,
  error,
  children,
}: {
  label: string;
  error?: string;
  children: React.ReactNode;
}): JSX.Element {
  return (
    <div>
      <span className="mb-1 block text-sm font-medium text-[#333333]">{label}</span>
      {children}
      {error && (
        <span role="alert" className="mt-1 block text-xs text-red-600">
          {error}
        </span>
      )}
    </div>
  );
}
