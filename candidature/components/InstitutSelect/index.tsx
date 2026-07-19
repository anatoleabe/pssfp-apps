'use client';

import { useMemo, useState } from 'react';
import { SearchableSelect } from '@/components/SearchableSelect';
import type { UniversitePays } from '@/lib/api/types';

const AUTRE = '__autre__';

export interface InstitutSelectProps {
  universites: UniversitePays[];
  value: string;
  onChange: (next: string) => void;
  error?: string;
}

/**
 * Liste déroulante recherchable "Établissement de délivrance", universités
 * regroupées par pays (CEMAC, cf. GET /v1/reference/universites) — avec
 * échappatoire "Autre" en texte libre pour tout établissement non listé.
 */
export function InstitutSelect({ universites, value, onChange, error }: InstitutSelectProps): JSX.Element {
  const options = useMemo(() => {
    const flat = universites.flatMap((group) =>
      group.universites.map((nom) => ({ value: nom, label: `${nom} (${group.pays})` })),
    );
    return [...flat, { value: AUTRE, label: 'Autre (préciser)' }];
  }, [universites]);

  const knownValues = useMemo(
    () => new Set(universites.flatMap((group) => group.universites)),
    [universites],
  );
  const [autreActive, setAutreActive] = useState(value !== '' && !knownValues.has(value));

  return (
    <div>
      <SearchableSelect
        testId="step3-institut"
        ariaLabel="Établissement de délivrance"
        options={options}
        value={autreActive ? AUTRE : value}
        onChange={(next) => {
          if (next === AUTRE) {
            setAutreActive(true);
            onChange('');
            return;
          }
          setAutreActive(false);
          onChange(next);
        }}
      />
      {autreActive && (
        <input
          type="text"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder="Précisez l'établissement"
          aria-label="Précisez l'établissement"
          className="mt-2 h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
        />
      )}
      {error && (
        <span role="alert" className="mt-1 block text-xs text-red-600">
          {error}
        </span>
      )}
    </div>
  );
}
