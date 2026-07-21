'use client';

import { useEffect, useState } from 'react';
import { SearchableSelect } from '@/components/SearchableSelect';
import { getDepartements, getRegions } from '@/lib/api/client';
import type { Departement, Pays, Region } from '@/lib/api/types';

export interface PaysRegionDepartementValue {
  pays_residence: string;
  region: string;
  departement: string;
}

export interface PaysRegionDepartementSelectProps {
  /** Liste des pays pré-fetched côté serveur (évite le flash blanc au mount). */
  initialPays: Pays[];
  value: PaysRegionDepartementValue;
  onChange: (next: PaysRegionDepartementValue) => void;
  ariaPaysLabel?: string;
  ariaRegionLabel?: string;
  ariaDepartementLabel?: string;
  errors?: Partial<Record<keyof PaysRegionDepartementValue, string>>;
}

/**
 * Cascade Pays -> (si CM) Région -> Département.
 *
 * - Le pays est hydraté depuis le SSR (P-min G PR E) — pas de flash.
 * - Régions et départements fetchés CSR au changement, cache HTTP backend 24h.
 * - Si pays_residence != 'CM', les selects région/département sont cachés.
 */
export function PaysRegionDepartementSelect({
  initialPays,
  value,
  onChange,
  ariaPaysLabel = 'Pays de résidence',
  ariaRegionLabel = 'Région du Cameroun',
  ariaDepartementLabel = 'Département',
  errors = {},
}: PaysRegionDepartementSelectProps): JSX.Element {
  const [regions, setRegions] = useState<Region[]>([]);
  const [departements, setDepartements] = useState<Departement[]>([]);
  const [loading, setLoading] = useState({ regions: false, departements: false });

  useEffect(() => {
    if (value.pays_residence !== 'CM') {
      setRegions([]);
      setDepartements([]);
      return;
    }
    setLoading((s) => ({ ...s, regions: true }));
    let cancelled = false;
    getRegions().then((r) => {
      if (cancelled) return;
      setLoading((s) => ({ ...s, regions: false }));
      if (r.ok) setRegions(r.data);
    });
    return () => {
      cancelled = true;
    };
  }, [value.pays_residence]);

  useEffect(() => {
    if (!value.region || value.pays_residence !== 'CM') {
      setDepartements([]);
      return;
    }
    setLoading((s) => ({ ...s, departements: true }));
    let cancelled = false;
    getDepartements(value.region).then((r) => {
      if (cancelled) return;
      setLoading((s) => ({ ...s, departements: false }));
      if (r.ok) setDepartements(r.data);
    });
    return () => {
      cancelled = true;
    };
  }, [value.region, value.pays_residence]);

  return (
    <div className="space-y-3">
      <div data-field-error={Boolean(errors.pays_residence)}>
        <label className="mb-1 block text-sm font-medium text-[#333333]">{ariaPaysLabel}</label>
        <SearchableSelect
          ariaLabel={ariaPaysLabel}
          testId="pays-residence-select"
          value={value.pays_residence}
          options={initialPays.map((p) => ({ value: p.code_iso, label: p.nom }))}
          onChange={(next) =>
            onChange({ pays_residence: next, region: '', departement: '' })
          }
          ariaInvalid={Boolean(errors.pays_residence)}
          ariaDescribedBy={errors.pays_residence ? 'pays-residence-error' : undefined}
        />
        {errors.pays_residence && <span id="pays-residence-error" role="alert" className="mt-1 block text-xs text-red-700">{errors.pays_residence}</span>}
      </div>

      {value.pays_residence === 'CM' && (
        <>
          <div data-field-error={Boolean(errors.region)}>
            <label className="mb-1 block text-sm font-medium text-[#333333]">{ariaRegionLabel}</label>
            <SearchableSelect
              ariaLabel={ariaRegionLabel}
              testId="region-select"
              value={value.region}
              disabled={loading.regions}
              options={regions.map((r) => ({ value: r.code, label: r.nom }))}
              onChange={(next) =>
                onChange({ ...value, region: next, departement: '' })
              }
              ariaInvalid={Boolean(errors.region)}
              ariaDescribedBy={errors.region ? 'region-error' : undefined}
            />
            {errors.region && <span id="region-error" role="alert" className="mt-1 block text-xs text-red-700">{errors.region}</span>}
          </div>
          <div data-field-error={Boolean(errors.departement)}>
            <label className="mb-1 block text-sm font-medium text-[#333333]">{ariaDepartementLabel}</label>
            <SearchableSelect
              ariaLabel={ariaDepartementLabel}
              testId="departement-select"
              value={value.departement}
              disabled={loading.departements || !value.region}
              options={departements.map((d) => ({ value: d.code, label: d.nom }))}
              onChange={(next) => onChange({ ...value, departement: next })}
              ariaInvalid={Boolean(errors.departement)}
              ariaDescribedBy={errors.departement ? 'departement-error' : undefined}
            />
            {errors.departement && <span id="departement-error" role="alert" className="mt-1 block text-xs text-red-700">{errors.departement}</span>}
          </div>
        </>
      )}
    </div>
  );
}
