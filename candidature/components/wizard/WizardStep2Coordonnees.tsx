'use client';

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
  return (
    <div className="space-y-5" data-testid="wizard-step-2">
      <h2 className="font-heading text-xl font-bold text-[#6B2FA0]">Étape 2 — Coordonnées</h2>

      <Field label="Pays d'origine" error={errors.pays_origine}>
        <SearchableSelect
          ariaLabel="Pays d'origine"
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
      />

      <div className="grid gap-4 md:grid-cols-2">
        <Field label="Adresse complète" error={errors.adresse}>
          <input
            data-testid="step2-adresse"
            type="text"
            value={data.adresse}
            onChange={(e) => onChange({ adresse: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#6B2FA0] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0]/30"
          />
        </Field>
        <Field label="Ville de résidence" error={errors.ville_residence}>
          <input
            type="text"
            value={data.ville_residence}
            onChange={(e) => onChange({ ville_residence: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#6B2FA0] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0]/30"
          />
        </Field>
      </div>

      <Field label="Lieu de naissance (ville)" error={errors.lieu_naissance}>
        <input
          type="text"
          value={data.lieu_naissance}
          onChange={(e) => onChange({ lieu_naissance: e.target.value })}
          className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#6B2FA0] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0]/30"
        />
      </Field>

      <Field label="Téléphone principal (login)" error={errors.phone_e164}>
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
        />
      </Field>

      <Field label="Email (optionnel — recommandé)" error={errors.email}>
        <input
          type="email"
          value={data.email}
          onChange={(e) => onChange({ email: e.target.value })}
          className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#6B2FA0] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0]/30"
        />
      </Field>
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
    <label className="block">
      <span className="mb-1 block text-sm font-medium text-[#333333]">{label}</span>
      {children}
      {error && (
        <span role="alert" className="mt-1 block text-xs text-red-600">
          {error}
        </span>
      )}
    </label>
  );
}
