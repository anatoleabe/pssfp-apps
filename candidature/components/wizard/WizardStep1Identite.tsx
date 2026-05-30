'use client';

import { SearchableSelect } from '@/components/SearchableSelect';
import type { Pays, Specialite } from '@/lib/api/types';
import type { WizardData } from './types';

export interface WizardStep1Props {
  data: WizardData;
  errors: Partial<Record<keyof WizardData, string>>;
  pays: Pays[];
  specialites: Specialite[];
  onChange: (patch: Partial<WizardData>) => void;
}

export function WizardStep1Identite({
  data,
  errors,
  pays,
  specialites,
  onChange,
}: WizardStep1Props): JSX.Element {
  return (
    <div className="space-y-5" data-testid="wizard-step-1">
      <h2 className="font-heading text-xl font-bold text-[#4A2E67]">
        Étape 1 — Vœu de spécialité &amp; identité
      </h2>

      <Field label="Spécialité demandée" error={errors.specialite}>
        <SearchableSelect
          ariaLabel="Spécialité demandée"
          testId="step1-specialite"
          value={data.specialite}
          options={specialites.map((s) => ({ value: s.label, label: s.label }))}
          onChange={(v) => onChange({ specialite: v })}
        />
      </Field>

      <div className="grid gap-4 md:grid-cols-2">
        <Field label="Type d'études" error={errors.type_etude}>
          <Radio
            name="type_etude"
            value={data.type_etude}
            options={[
              { value: 'presentiel', label: 'Présentiel' },
              { value: 'distanciel', label: 'Distanciel' },
            ]}
            onChange={(v) => onChange({ type_etude: v as 'presentiel' | 'distanciel' })}
          />
        </Field>

        <Field label="Première langue" error={errors.premiere_langue}>
          <Radio
            name="premiere_langue"
            value={data.premiere_langue}
            options={[
              { value: 'fr', label: 'Français' },
              { value: 'en', label: 'Anglais' },
            ]}
            onChange={(v) => onChange({ premiere_langue: v as 'fr' | 'en' })}
          />
        </Field>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <Field label="Civilité" error={errors.civilite}>
          <select
            data-testid="step1-civilite"
            value={data.civilite}
            onChange={(e) =>
              onChange({ civilite: e.target.value as 'M.' | 'Mme' | 'Mlle' })
            }
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          >
            <option>M.</option>
            <option>Mme</option>
            <option>Mlle</option>
          </select>
        </Field>
        <Field label="Prénom(s)" error={errors.prenom}>
          <input
            data-testid="step1-prenom"
            type="text"
            value={data.prenom}
            onChange={(e) => onChange({ prenom: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
        </Field>
        <Field label="Nom" error={errors.nom}>
          <input
            data-testid="step1-nom"
            type="text"
            value={data.nom}
            onChange={(e) => onChange({ nom: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
        </Field>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <Field label="Nom de jeune fille (si applicable)" error={errors.epouse}>
          <input
            type="text"
            value={data.epouse}
            onChange={(e) => onChange({ epouse: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
        </Field>
        <Field label="Date de naissance" error={errors.date_naissance}>
          <input
            data-testid="step1-date-naissance"
            type="date"
            value={data.date_naissance}
            onChange={(e) => onChange({ date_naissance: e.target.value })}
            max={new Date().toISOString().slice(0, 10)}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
        </Field>
        <Field label="Genre" error={errors.genre}>
          <select
            value={data.genre}
            onChange={(e) => onChange({ genre: e.target.value as 'M' | 'F' | 'autre' })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          >
            <option value="M">Masculin</option>
            <option value="F">Féminin</option>
            <option value="autre">Autre</option>
          </select>
        </Field>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Field label="Situation matrimoniale" error={errors.statut_matrimonial}>
          <select
            value={data.statut_matrimonial}
            onChange={(e) => onChange({ statut_matrimonial: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          >
            <option>Célibataire</option>
            <option>Marié(e)</option>
            <option>Divorcé(e)</option>
            <option>Veuf / Veuve</option>
            <option>Autre</option>
          </select>
        </Field>
        <Field label="Nationalité" error={errors.nationalite}>
          <SearchableSelect
            ariaLabel="Nationalité"
            value={data.nationalite}
            options={pays.map((p) => ({ value: p.code_iso, label: p.nom }))}
            onChange={(v) => onChange({ nationalite: v })}
          />
        </Field>
      </div>
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

function Radio({
  name,
  value,
  options,
  onChange,
}: {
  name: string;
  value: string;
  options: Array<{ value: string; label: string }>;
  onChange: (next: string) => void;
}): JSX.Element {
  return (
    <div className="flex gap-3">
      {options.map((o) => (
        <label
          key={o.value}
          className={`flex-1 cursor-pointer rounded-md border px-3 py-2 text-sm ${
            value === o.value ? 'border-[#4A2E67] bg-[#F4EFFA] text-[#4A2E67]' : 'border-gray-300'
          }`}
        >
          <input
            type="radio"
            name={name}
            value={o.value}
            checked={value === o.value}
            onChange={() => onChange(o.value)}
            className="sr-only"
          />
          {o.label}
        </label>
      ))}
    </div>
  );
}
