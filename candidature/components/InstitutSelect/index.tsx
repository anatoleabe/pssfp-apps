'use client';

import { useTranslations } from 'next-intl';

import { useMemo, useState } from 'react';
import { SearchableSelect } from '@/components/SearchableSelect';
import type { UniversitePays } from '@/lib/api/types';

const AUTRE = '__autre__';

export interface InstitutSelectProps {
  universites: UniversitePays[];
  value: string;
  onChange: (next: string) => void;
  error?: string;
  /**
   * Le composant est instancié deux fois sur l'étape 3 (diplôme le plus élevé
   * et diplôme requis) : sans identifiant distinct, les deux sélecteurs
   * partageraient le même `data-testid` et le même libellé accessible.
   */
  testId?: string;
  ariaLabel?: string;
}

/**
 * Liste déroulante recherchable "Établissement de délivrance", universités
 * regroupées par pays (CEMAC, cf. GET /v1/reference/universites) — avec
 * échappatoire "Autre" en texte libre pour tout établissement non listé.
 */
export function InstitutSelect({
  universites,
  value,
  onChange,
  error,
  testId = 'step3-institut',
  ariaLabel,
}: InstitutSelectProps): JSX.Element {
  const tsi = useTranslations('selects.institut');
  const tf = useTranslations('dossier.fields');
  // Défaut traduit et non littéral français : sans cela un lecteur d'écran
  // annonçait « Établissement de délivrance » sur la version anglaise.
  const resolvedAriaLabel = ariaLabel ?? tf('institut');
  const options = useMemo(() => {
    const flat = universites.flatMap((group) =>
      group.universites.map((nom) => ({ value: nom, label: `${nom} (${group.pays})` })),
    );
    return [...flat, { value: AUTRE, label: tsi('other') }];
  }, [universites, tsi]);

  const knownValues = useMemo(
    () => new Set(universites.flatMap((group) => group.universites)),
    [universites],
  );
  const [autreActive, setAutreActive] = useState(value !== '' && !knownValues.has(value));

  return (
    <div>
      <SearchableSelect
        testId={testId}
        ariaLabel={resolvedAriaLabel}
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
          placeholder={tsi('precisePlaceholder')}
          aria-label={tsi('precisePlaceholder')}
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
