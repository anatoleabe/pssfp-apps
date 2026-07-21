'use client';

import { DiplomeSelect } from '@/components/DiplomeSelect';
import { InstitutSelect } from '@/components/InstitutSelect';
import type { Diplome, UniversitePays } from '@/lib/api/types';
import type { WizardData } from './types';

export interface WizardStep3Props {
  data: WizardData;
  errors: Partial<Record<keyof WizardData, string>>;
  onChange: (patch: Partial<WizardData>) => void;
  diplomes: Diplome[];
  universites: UniversitePays[];
}

export function WizardStep3Diplome({
  data,
  errors,
  onChange,
  diplomes,
  universites,
}: WizardStep3Props): JSX.Element {
  const showEmployer = data.statut_actuel && data.statut_actuel !== 'Etudiant';

  return (
    <div className="space-y-5" data-testid="wizard-step-3">
      <h2 className="font-heading text-xl font-bold text-[#4A2E67]">Étape 3 — Diplôme &amp; situation professionnelle</h2>

      <div className="grid gap-4 md:grid-cols-2">
        <Field label="Diplôme le plus élevé obtenu" error={errors.diplome_obtenu}>
          <DiplomeSelect
            diplomes={diplomes}
            value={data.diplome_obtenu}
            onChange={(v) => onChange({ diplome_obtenu: v })}
            error={undefined}
          />
        </Field>
        <Field label="Année d'obtention" error={errors.annee_diplome}>
          <input
            data-testid="step3-annee-diplome"
            type="number"
            inputMode="numeric"
            min={1950}
            max={new Date().getFullYear()}
            value={data.annee_diplome}
            onChange={(e) =>
              onChange({ annee_diplome: e.target.value === '' ? '' : Number(e.target.value) })
            }
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
        </Field>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Field label="Établissement de délivrance" error={errors.institut}>
          <InstitutSelect
            universites={universites}
            value={data.institut}
            onChange={(v) => onChange({ institut: v })}
            error={undefined}
          />
        </Field>
        <Field label="Spécialité du diplôme" error={errors.specialite_diplome}>
          <input
            type="text"
            value={data.specialite_diplome}
            onChange={(e) => onChange({ specialite_diplome: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
        </Field>
      </div>

      <Field label="Situation actuelle" error={errors.statut_actuel}>
        <select
          data-testid="step3-statut-actuel"
          value={data.statut_actuel}
          onChange={(e) =>
            onChange({
              statut_actuel: e.target.value as WizardData['statut_actuel'],
            })
          }
          className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
        >
          <option value="">— Choisir —</option>
          <option value="Etudiant">Étudiant</option>
          <option value="Fonctionnaire-Contractuel">Fonctionnaire / Contractuel</option>
          <option value="Prive">Secteur privé</option>
        </select>
      </Field>

      {showEmployer && (
        <div className="space-y-4 rounded-md border border-[#F4EFFA] bg-[#FAF7FF] p-4">
          <Field label="Employeur" error={errors.employeur}>
            <input
              type="text"
              value={data.employeur}
              onChange={(e) => onChange({ employeur: e.target.value })}
              className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
            />
          </Field>
          <Field label="Adresse de l'employeur" error={errors.adresse_employeur}>
            <input
              type="text"
              value={data.adresse_employeur}
              onChange={(e) => onChange({ adresse_employeur: e.target.value })}
              className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
            />
          </Field>
          <Field label="Téléphone employeur" error={errors.tel_employeur}>
            <input
              type="text"
              value={data.tel_employeur}
              onChange={(e) => onChange({ tel_employeur: e.target.value })}
              className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
            />
          </Field>
        </div>
      )}

      <Field label="Comment avez-vous connu le PSSFP ? (optionnel)" error={errors.moyen_connaissance}>
        <select
          value={data.moyen_connaissance}
          onChange={(e) => onChange({ moyen_connaissance: e.target.value })}
          className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
        >
          <option value="">— Choisir —</option>
          <option>Site web</option>
          <option>Réseaux sociaux</option>
          <option>Recommandation</option>
          <option>Presse</option>
          <option>Ancien candidat</option>
          <option>Autre</option>
        </select>
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
    <label className="block" data-field-error={Boolean(error)}>
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
