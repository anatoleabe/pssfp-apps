'use client';

import { useMemo, useState } from 'react';
import { SearchableSelect } from '@/components/SearchableSelect';
import type { EmployeurPublicGroup } from '@/lib/api/types';

const AUTRE = '__autre_employeur_public__';

interface EmployeurPublicSelectProps {
  groups: EmployeurPublicGroup[];
  value: string;
  onChange: (next: string) => void;
  error?: string;
}

/** Sélecteur recherchable des employeurs publics, avec saisie libre de secours. */
export function EmployeurPublicSelect({
  groups,
  value,
  onChange,
  error,
}: EmployeurPublicSelectProps): JSX.Element {
  const knownValues = useMemo(
    () => new Set(groups.flatMap((group) => group.employeurs)),
    [groups],
  );
  const options = useMemo(
    () => [
      ...groups.flatMap((group) =>
        group.employeurs.map((employeur) => ({
          value: employeur,
          label: `${employeur} — ${group.categorie}`,
        })),
      ),
      { value: AUTRE, label: 'Autre organisme public (préciser)' },
    ],
    [groups],
  );
  const [autreActive, setAutreActive] = useState(value !== '' && !knownValues.has(value));

  return (
    <div>
      <SearchableSelect
        testId="employeur-public-select"
        ariaLabel="Administration, entreprise ou établissement public employeur"
        placeholder="Rechercher une administration ou un organisme…"
        options={options}
        value={autreActive ? AUTRE : value}
        ariaInvalid={Boolean(error)}
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
          onChange={(event) => onChange(event.target.value)}
          placeholder="Nom officiel de l’organisme public"
          aria-label="Précisez le nom de l’organisme public"
          aria-invalid={Boolean(error)}
          className="mt-2 h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
        />
      )}
    </div>
  );
}
