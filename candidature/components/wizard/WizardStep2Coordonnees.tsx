'use client';

import { useTranslations } from 'next-intl';

import { PaysRegionDepartementSelect } from '@/components/PaysRegionDepartementSelect';
import { PhoneInput } from '@/components/PhoneInput';
import { SearchableSelect } from '@/components/SearchableSelect';
import type { Pays } from '@/lib/api/types';
import type { WizardData } from './types';

export interface WizardStep2Props {
  data: WizardData;
  errors: Partial<Record<keyof WizardData, string>>;
  pays: Pays[];
  onChange: (patch: Partial<WizardData>) => void;
}

export function WizardStep2Coordonnees({ data, errors, pays, onChange }: WizardStep2Props): JSX.Element {
  const t = useTranslations('wizard.step2');

  return (
    <div className="space-y-5" data-testid="wizard-step-2">
      <h2 className="font-heading text-xl font-bold text-[#4A2E67]">{t('title')}</h2>

      <Field label={t('paysOrigine')} error={errors.pays_origine}>
        <SearchableSelect
          ariaLabel={t('paysOrigine')}
          value={data.pays_origine}
          options={pays.map((p) => ({ value: p.code_iso, label: p.nom }))}
          onChange={(v) => onChange({ pays_origine: v })}
        />
      </Field>

      <PaysRegionDepartementSelect
        initialPays={pays}
        value={{
          pays_residence: data.pays_residence,
          region: data.region,
          departement: data.departement,
        }}
        onChange={(next) =>
          onChange({
            pays_residence: next.pays_residence,
            region: next.region,
            departement: next.departement,
          })
        }
        errors={{
          pays_residence: errors.pays_residence,
          region: errors.region,
          departement: errors.departement,
        }}
      />

      <div className="grid gap-4 md:grid-cols-2">
        <Field label={t('adresse')} error={errors.adresse} required>
          <input
            data-testid="step2-adresse"
            type="text"
            value={data.adresse}
            onChange={(e) => onChange({ adresse: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
        </Field>
        <Field label={t('villeResidence')} error={errors.ville_residence} required>
          <input
            type="text"
            value={data.ville_residence}
            onChange={(e) => onChange({ ville_residence: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
        </Field>
      </div>

      <Field label={t('lieuNaissance')} error={errors.lieu_naissance} required>
        <input
          type="text"
          value={data.lieu_naissance}
          onChange={(e) => onChange({ lieu_naissance: e.target.value })}
          className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
        />
      </Field>

      <Field label={t('phonePrincipal')} error={errors.phone_e164} required>
        <PhoneInput
          pays={pays}
          testIdPrefix="step2-phone"
          value={{
            countryCode: data.phone_country,
            indicatif: data.indicatif1,
            local: data.telephone1,
            e164: data.phone_e164,
          }}
          onChange={(v) =>
            onChange({
              phone_country: v.countryCode,
              indicatif1: v.indicatif,
              telephone1: v.local,
              phone_e164: v.e164,
            })
          }
          ariaInvalid={Boolean(errors.phone_e164)}
        />
      </Field>

      <Field label={t('email')} error={errors.email} required>
        <input
          type="email"
          required
          autoComplete="email"
          value={data.email}
          onChange={(e) => onChange({ email: e.target.value })}
          placeholder={t('emailPlaceholder')}
          className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
        />
        <span className="mt-1 block text-xs text-[#666]">{t('emailHint')}</span>
      </Field>
    </div>
  );
}

/**
 * `required` porte l'astérisque plutôt que le libellé lui-même : l'audit A-30
 * relève que le marquage était incohérent, certains champs obligatoires n'étant
 * pas signalés et l'astérisque étant parfois inclus dans la traduction.
 */
function Field({
  label,
  error,
  required = false,
  children,
}: {
  label: string;
  error?: string;
  required?: boolean;
  children: React.ReactNode;
}): JSX.Element {
  return (
    <label className="block" data-field-error={Boolean(error)}>
      <span className="mb-1 block text-sm font-medium text-[#333333]">
        {label}
        {required && (
          <span aria-hidden="true" className="ml-0.5 text-red-600">
            *
          </span>
        )}
      </span>
      {children}
      {error && (
        <span role="alert" className="mt-1 block text-xs text-red-600">
          {error}
        </span>
      )}
    </label>
  );
}
