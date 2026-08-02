'use client';

import { useTranslations } from 'next-intl';

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

/**
 * Situations matrimoniales : la valeur stockée reste le libellé français, qui
 * est celui enregistré en base pour les dossiers existants. Seul l'affichage
 * est traduit — changer la valeur casserait la relecture des dossiers déjà
 * soumis et la contrainte CHECK côté PostgreSQL.
 */
const STATUTS_MATRIMONIAUX = [
  { value: 'Célibataire', key: 'celibataire' },
  { value: 'Marié(e)', key: 'marie' },
  { value: 'Divorcé(e)', key: 'divorce' },
  { value: 'Veuf / Veuve', key: 'veuf' },
  { value: 'Autre', key: 'autre' },
] as const;

export function WizardStep1Identite({
  data,
  errors,
  pays,
  specialites,
  onChange,
}: WizardStep1Props): JSX.Element {
  const t = useTranslations('wizard.step1');
  const to = useTranslations('options');

  return (
    <div className="space-y-5" data-testid="wizard-step-1">
      <h2 className="font-heading text-xl font-bold text-[#4A2E67]">{t('title')}</h2>

      <Field label={t('specialite')} error={errors.specialite} required>
        <SearchableSelect
          ariaLabel={t('specialite')}
          testId="step1-specialite"
          value={data.specialite}
          options={specialites.map((s) => ({ value: s.label, label: s.label }))}
          onChange={(v) => onChange({ specialite: v })}
        />
      </Field>
      {data.specialite && (
        <aside
          className="rounded-md border border-[#E4DCEE] bg-[#FAF7FF] p-4 text-sm text-[#4B4B4B]"
          aria-label={t('ficheAria', { specialite: data.specialite })}
        >
          <h3 className="font-heading text-lg font-semibold text-[#4A2E67]">{data.specialite}</h3>
          {/* Fiche générique en attendant les cinq fiches rédigées (audit A-05).
              Le nom de la filière est au moins interpolé : la chaîne de gabarit
              « cette spécialité » était affichée telle quelle au candidat. */}
          <dl className="mt-2 grid gap-2 sm:grid-cols-2">
            <div>
              <dt className="font-semibold">{t('ficheObjectifLabel')}</dt>
              <dd>{t('ficheObjectif', { specialite: data.specialite })}</dd>
            </div>
            <div>
              <dt className="font-semibold">{t('ficheProfilLabel')}</dt>
              <dd>{t('ficheProfil')}</dd>
            </div>
            <div>
              <dt className="font-semibold">{t('ficheDebouchesLabel')}</dt>
              <dd>{t('ficheDebouches')}</dd>
            </div>
            <div>
              <dt className="font-semibold">{t('fichePlacesLabel')}</dt>
              <dd>{t('fichePlaces')}</dd>
            </div>
          </dl>
        </aside>
      )}

      <div className="grid gap-4 md:grid-cols-2">
        <Field label={t('typeEtude')} error={errors.type_etude} required>
          <Radio
            name="type_etude"
            value={data.type_etude}
            options={[
              { value: 'presentiel', label: to('presentiel') },
              { value: 'distanciel', label: to('distanciel') },
            ]}
            onChange={(v) => onChange({ type_etude: v as 'presentiel' | 'distanciel' })}
          />
        </Field>

        <Field label={t('premiereLangue')} error={errors.premiere_langue} required>
          <Radio
            name="premiere_langue"
            value={data.premiere_langue}
            options={[
              { value: 'fr', label: to('langueFr') },
              { value: 'en', label: to('langueEn') },
            ]}
            onChange={(v) => onChange({ premiere_langue: v as 'fr' | 'en' })}
          />
        </Field>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <Field label={t('civilite')} error={errors.civilite} required>
          <select
            data-testid="step1-civilite"
            value={data.civilite}
            onChange={(e) => onChange({ civilite: e.target.value as 'M.' | 'Mme' })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          >
            <option>M.</option>
            <option>Mme</option>
          </select>
        </Field>
        <Field label={t('prenom')} error={errors.prenom} required>
          <input
            data-testid="step1-prenom"
            type="text"
            value={data.prenom}
            onChange={(e) => onChange({ prenom: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
        </Field>
        <Field label={t('nom')} error={errors.nom} required>
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
        <Field label={t('nomUsage')} error={errors.epouse}>
          <input
            type="text"
            value={data.epouse}
            onChange={(e) => onChange({ epouse: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
        </Field>
        <Field label={t('dateNaissance')} error={errors.date_naissance} required>
          <input
            data-testid="step1-date-naissance"
            type="date"
            value={data.date_naissance}
            onChange={(e) => onChange({ date_naissance: e.target.value })}
            max={new Date().toISOString().slice(0, 10)}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
        </Field>
        <Field label={t('genre')} error={errors.genre} required>
          <select
            value={data.genre}
            onChange={(e) => onChange({ genre: e.target.value as 'M' | 'F' | 'autre' })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          >
            <option value="M">{to('genreM')}</option>
            <option value="F">{to('genreF')}</option>
            <option value="autre">{to('genreAutre')}</option>
          </select>
        </Field>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Field label={t('statutMatrimonial')} error={errors.statut_matrimonial} required>
          <select
            value={data.statut_matrimonial}
            onChange={(e) => onChange({ statut_matrimonial: e.target.value })}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          >
            {STATUTS_MATRIMONIAUX.map((s) => (
              <option key={s.value} value={s.value}>
                {to(`marital.${s.key}`)}
              </option>
            ))}
          </select>
        </Field>
        <Field label={t('nationalite')} error={errors.nationalite} required>
          <SearchableSelect
            ariaLabel={t('nationalite')}
            value={data.nationalite}
            options={pays.map((p) => ({ value: p.code_iso, label: p.nom }))}
            onChange={(v) => onChange({ nationalite: v })}
          />
        </Field>
      </div>
    </div>
  );
}

/** Astérisque porté par `required` et non par le libellé traduit (audit A-30). */
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
