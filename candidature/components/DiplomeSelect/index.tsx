'use client';

import { useMemo, useState } from 'react';
import type { Diplome } from '@/lib/api/types';

const AUTRE = '__autre__';

export interface DiplomeSelectProps {
  diplomes: Diplome[];
  value: string;
  onChange: (next: string) => void;
  error?: string;
}

/**
 * Liste déroulante "Diplôme le plus élevé obtenu" avec échappatoire "Autre"
 * en texte libre — la liste de référence (cf. GET /v1/reference/diplomes)
 * ne prétend pas couvrir tous les intitulés possibles.
 */
export function DiplomeSelect({ diplomes, value, onChange, error }: DiplomeSelectProps): JSX.Element {
  const knownLabels = useMemo(() => new Set(diplomes.map((d) => d.label)), [diplomes]);
  const [autreActive, setAutreActive] = useState(value !== '' && !knownLabels.has(value));

  return (
    <div>
      <select
        data-testid="step3-diplome-obtenu"
        value={autreActive ? AUTRE : value}
        onChange={(e) => {
          if (e.target.value === AUTRE) {
            setAutreActive(true);
            onChange('');
            return;
          }
          setAutreActive(false);
          onChange(e.target.value);
        }}
        className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
      >
        <option value="">— Choisir —</option>
        {diplomes.map((d) => (
          <option key={d.slug} value={d.label}>
            {d.label}
          </option>
        ))}
        <option value={AUTRE}>Autre</option>
      </select>
      {autreActive && (
        <input
          type="text"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder="Précisez le diplôme"
          aria-label="Précisez le diplôme"
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
