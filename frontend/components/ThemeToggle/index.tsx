'use client';

import { useCallback, useEffect, useState } from 'react';
import { Moon, Sun } from 'lucide-react';
import { useTranslations } from 'next-intl';

const STORAGE_KEY = 'pssfp-theme';

type Theme = 'light' | 'dark';

function readInitialTheme(): Theme {
  if (typeof document === 'undefined') return 'light';
  return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

interface ThemeToggleProps {
  /** Variante compacte pour mobile / drawer. */
  compact?: boolean;
  /** Override le `data-testid` (utile pour distinguer desktop vs mobile). */
  testId?: string;
}

export function ThemeToggle({ compact = false, testId }: ThemeToggleProps): JSX.Element {
  const t = useTranslations('theme');
  const [theme, setTheme] = useState<Theme>('light');
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setTheme(readInitialTheme());
    setMounted(true);
  }, []);

  const toggle = useCallback((): void => {
    const next: Theme = theme === 'dark' ? 'light' : 'dark';
    setTheme(next);
    document.documentElement.classList.toggle('dark', next === 'dark');
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      /* storage indisponible (mode privé) — silencieux */
    }
  }, [theme]);

  // Avant l'hydration on rend un placeholder neutre (évite le mismatch SSR/CSR).
  const isDark = mounted && theme === 'dark';
  const label = isDark ? t('switchToLight') : t('switchToDark');

  if (compact) {
    return (
      <button
        type="button"
        onClick={toggle}
        aria-label={label}
        title={label}
        data-testid={testId ?? 'theme-toggle-mobile-drawer'}
        className="inline-flex items-center justify-between rounded px-2 py-3 text-[var(--pssfp-text)] transition-colors hover:text-[var(--pssfp-primary)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pssfp-accent)]"
      >
        <span className="flex items-center gap-2">
          {isDark ? <Sun size={16} aria-hidden="true" /> : <Moon size={16} aria-hidden="true" />}
          <span>{label}</span>
        </span>
      </button>
    );
  }

  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={label}
      aria-pressed={isDark}
      title={label}
      data-testid={testId ?? 'theme-toggle'}
      className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-[var(--pssfp-border)] bg-[var(--pssfp-bg-elevated)] text-[var(--pssfp-text)] transition-all duration-200 hover:border-[var(--pssfp-primary)] hover:text-[var(--pssfp-primary)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pssfp-accent)] focus-visible:ring-offset-2"
    >
      <span aria-hidden="true" className="relative h-4 w-4">
        <Sun
          size={16}
          className={`absolute inset-0 transition-all duration-300 ${isDark ? 'rotate-0 scale-100 opacity-100' : '-rotate-90 scale-0 opacity-0'}`}
        />
        <Moon
          size={16}
          className={`absolute inset-0 transition-all duration-300 ${isDark ? 'rotate-90 scale-0 opacity-0' : 'rotate-0 scale-100 opacity-100'}`}
        />
      </span>
    </button>
  );
}
