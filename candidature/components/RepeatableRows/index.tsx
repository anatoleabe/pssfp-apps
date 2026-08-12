'use client';

import { useRef, useState } from 'react';

import type { RepeatableColumn } from '@/lib/diplomes/rows';

export interface RepeatableRowsProps<T extends object> {
  /** Intitulé du <fieldset>. */
  legend: string;
  addLabel: string;
  /** Reçoit le rang affiché (1-based) : « Supprimer le diplôme 2 ». */
  removeLabel: (position: number) => string;
  addedAnnouncement: string;
  removedAnnouncement: string;
  maxReachedMessage: string;
  columns: ReadonlyArray<RepeatableColumn<T>>;
  /** Libellé de chaque cellule, résolu par l'appelant (next-intl). */
  columnLabel: (column: RepeatableColumn<T>) => string;
  rows: readonly T[];
  emptyRow: T;
  max: number;
  /** Préfixe des clés d'erreur : `autres_diplomes` → `autres_diplomes.0.intitule`. */
  fieldName: string;
  errors: Record<string, string | undefined>;
  testIdPrefix: string;
  onChange: (rows: T[]) => void;
}

/**
 * Bloc de lignes répétables accessible : un <fieldset> par bloc, un <label>
 * par cellule, un bouton de suppression nommé sans ambiguïté et une région
 * `aria-live` qui annonce ajouts et suppressions.
 *
 * Aucune mutation : chaque ajout, modification ou suppression produit un
 * nouveau tableau.
 */
export function RepeatableRows<T extends object>({
  legend,
  addLabel,
  removeLabel,
  addedAnnouncement,
  removedAnnouncement,
  maxReachedMessage,
  columns,
  columnLabel,
  rows,
  emptyRow,
  max,
  fieldName,
  errors,
  testIdPrefix,
  onChange,
}: RepeatableRowsProps<T>): JSX.Element {
  const [announcement, setAnnouncement] = useState('');
  const containerRef = useRef<HTMLDivElement | null>(null);
  const atMax = rows.length >= max;

  const addRow = (): void => {
    if (atMax) {
      setAnnouncement(maxReachedMessage);
      return;
    }
    const nextIndex = rows.length;
    onChange([...rows, { ...emptyRow }]);
    setAnnouncement(addedAnnouncement);
    // Le focus part sur le premier champ de la ligne créée : sans cela il
    // resterait sur « Ajouter » et rien n'indiquerait où saisir.
    requestAnimationFrame(() => {
      containerRef.current
        ?.querySelector<HTMLInputElement>(
          `[data-testid="${testIdPrefix}-${nextIndex}-${String(columns[0]?.key ?? '')}"]`,
        )
        ?.focus();
    });
  };

  const removeRow = (index: number): void => {
    onChange(rows.filter((_, i) => i !== index));
    setAnnouncement(removedAnnouncement);
  };

  const patchRow = (index: number, key: keyof T & string, value: string): void => {
    const column = columns.find((c) => c.key === key);
    const parsed = column?.type === 'year' ? (value === '' ? '' : Number(value)) : value;
    onChange(rows.map((row, i) => (i === index ? { ...row, [key]: parsed } : row)));
  };

  return (
    <fieldset className="rounded-md border border-[#E4DCEE] bg-[#FAF7FF] p-4">
      <legend className="px-1 text-sm font-semibold text-[#4A2E67]">{legend}</legend>

      <div ref={containerRef} className="space-y-4">
        {rows.map((row, index) => (
          <div
            key={index}
            data-testid={`${testIdPrefix}-row-${index}`}
            className="grid gap-3 rounded-md border border-[#E4DCEE] bg-white p-3 md:grid-cols-[1fr_1fr_8rem_auto]"
          >
            {columns.map((column) => {
              const errorKey = `${fieldName}.${index}.${column.key}`;
              const error = errors[errorKey];
              const raw = row[column.key] as unknown;
              return (
                <label key={column.key} className="block" data-field-error={Boolean(error)}>
                  <span className="mb-1 block text-xs font-medium text-[#333333]">
                    {columnLabel(column)}
                  </span>
                  <input
                    data-testid={`${testIdPrefix}-${index}-${column.key}`}
                    type={column.type === 'year' ? 'number' : 'text'}
                    inputMode={column.type === 'year' ? 'numeric' : undefined}
                    min={column.type === 'year' ? 1950 : undefined}
                    max={column.type === 'year' ? new Date().getFullYear() : undefined}
                    value={raw === null || raw === undefined ? '' : String(raw)}
                    aria-invalid={Boolean(error)}
                    onChange={(event) => patchRow(index, column.key, event.target.value)}
                    className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
                  />
                  {error && (
                    <span role="alert" className="mt-1 block text-xs text-red-600">
                      {error}
                    </span>
                  )}
                </label>
              );
            })}

            <div className="flex items-end">
              <button
                type="button"
                data-testid={`${testIdPrefix}-remove-${index}`}
                onClick={() => removeRow(index)}
                className="h-11 rounded-md border border-gray-300 px-3 text-sm text-[#333333] hover:border-red-600 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67]"
              >
                {removeLabel(index + 1)}
              </button>
            </div>
          </div>
        ))}
      </div>

      <button
        type="button"
        data-testid={`${testIdPrefix}-add`}
        onClick={addRow}
        disabled={atMax}
        className="mt-4 rounded-md border border-[#4A2E67] px-4 py-2 text-sm font-medium text-[#4A2E67] hover:bg-[#F4EFFA] disabled:cursor-not-allowed disabled:border-gray-300 disabled:text-gray-400"
      >
        {addLabel}
      </button>

      <p aria-live="polite" className="sr-only">
        {announcement}
      </p>
    </fieldset>
  );
}
