'use client';

import { useTranslations } from 'next-intl';

import { useEffect, useId, useMemo, useRef, useState } from 'react';

export interface SearchableSelectOption {
  value: string;
  label: string;
}

export interface SearchableSelectProps {
  options: SearchableSelectOption[];
  value: string;
  onChange: (next: string) => void;
  placeholder?: string;
  ariaLabel: string;
  testId?: string;
  disabled?: boolean;
  ariaInvalid?: boolean;
  ariaDescribedBy?: string;
}

/**
 * Select avec recherche typeahead (P-min-3 PR E).
 *
 * Composant maison léger, sans dépendance externe :
 * - Input avec filtrage en temps réel
 * - Dropdown qui se ferme à la sélection ou au blur
 * - Navigation clavier minimale (Esc pour fermer)
 *
 * Pour les cas exotiques (multi-sélection, virtualisation, etc.) on basculerait
 * sur Radix Combobox ou Headless UI Combobox dans une PR ultérieure.
 */
export function SearchableSelect({
  options,
  value,
  onChange,
  placeholder = 'Sélectionner…',
  ariaLabel,
  testId,
  disabled,
  ariaInvalid,
  ariaDescribedBy,
}: SearchableSelectProps): JSX.Element {
  const tss = useTranslations('selects.searchable');
  const [open, setOpen] = useState(false);
  const [filter, setFilter] = useState('');
  const wrapperRef = useRef<HTMLDivElement | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const listboxId = useId();

  const selected = useMemo(
    () => options.find((opt) => opt.value === value) ?? null,
    [options, value],
  );

  const filtered = useMemo(() => {
    if (!filter.trim()) {
      return options;
    }
    const q = filter.toLowerCase();
    return options.filter((opt) => opt.label.toLowerCase().includes(q));
  }, [options, filter]);

  useEffect(() => {
    const onClickOutside = (e: MouseEvent): void => {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, []);

  return (
    <div ref={wrapperRef} className="relative" data-testid={testId}>
      <button
        type="button"
        role="combobox"
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={listboxId}
        aria-invalid={ariaInvalid}
        aria-describedby={ariaDescribedBy}
        disabled={disabled}
        onClick={() => {
          if (disabled) return;
          setOpen((prev) => !prev);
          setTimeout(() => inputRef.current?.focus(), 0);
        }}
        className="flex h-11 w-full items-center justify-between rounded-md border border-gray-300 bg-white px-3 text-left text-sm text-[#333333] focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400"
      >
        {/* `text-gray-500` et non `text-gray-400` : le texte de substitution est
            du contenu lu, il doit tenir le 4.5:1 exigé par WCAG 2.1 AA
            (gray-400 sur blanc plafonne à 2.85:1). */}
        <span className={selected ? '' : 'text-gray-500'}>
          {selected ? selected.label : placeholder}
        </span>
        <span aria-hidden className="ml-2 text-gray-400">
          ▾
        </span>
      </button>

      {open && !disabled && (
        <div
          id={listboxId}
          role="listbox"
          aria-label={ariaLabel}
          className="absolute left-0 right-0 z-20 mt-1 max-h-64 overflow-auto rounded-md border border-gray-300 bg-white shadow-lg"
        >
          <div className="sticky top-0 border-b border-gray-200 bg-white p-2">
            <input
              ref={inputRef}
              type="text"
              aria-label={`Rechercher ${ariaLabel.toLowerCase()}`}
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Escape') {
                  setOpen(false);
                }
              }}
              placeholder={tss('search')}
              className="h-9 w-full rounded-md border border-gray-200 px-2 text-sm focus:border-[#4A2E67] focus:outline-none"
            />
          </div>
          <ul className="py-1">
            {filtered.length === 0 ? (
              <li className="px-3 py-2 text-sm text-gray-500">{tss('noResult')}</li>
            ) : (
              filtered.map((opt) => (
                <li
                  key={opt.value}
                  role="option"
                  aria-selected={opt.value === value}
                  className={`px-3 py-2 text-sm ${
                    opt.value === value ? 'bg-[#F4EFFA] font-medium text-[#4A2E67]' : ''
                  }`}
                >
                  <button
                    type="button"
                    onClick={() => {
                      onChange(opt.value);
                      setOpen(false);
                      setFilter('');
                    }}
                    className={`w-full text-left ${
                      opt.value === value ? '' : 'hover:underline'
                    }`}
                  >
                    {opt.label}
                  </button>
                </li>
              ))
            )}
          </ul>
        </div>
      )}
    </div>
  );
}
