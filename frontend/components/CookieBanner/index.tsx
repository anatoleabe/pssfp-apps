'use client';

import { useEffect, useState } from 'react';
import { useTranslations } from 'next-intl';

const STORAGE_KEY = 'pssfp_cookie_consent_v1';
const SIX_MONTHS_MS = 6 * 30 * 24 * 60 * 60 * 1000;

interface ConsentRecord {
  decision: 'granted' | 'denied';
  at: number;
}

function readConsent(): ConsentRecord | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as ConsentRecord;
    if (Date.now() - parsed.at > SIX_MONTHS_MS) {
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

function writeConsent(decision: ConsentRecord['decision']): void {
  try {
    window.localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({ decision, at: Date.now() } satisfies ConsentRecord),
    );
  } catch {
    // ignore (mode privé strict)
  }
}

/**
 * Bandeau de consentement cookies/Matomo. Persiste 6 mois.
 *
 * Pas de cookie tiers tant que l'utilisateur n'a pas accepté. La décision
 * est stockée en localStorage (pas un cookie) — les vrais cookies de tracking
 * seront déclenchés conditionnellement par Matomo plus tard via le hook
 * `useMatomoConsent` (à créer en PR de suivi).
 */
export function CookieBanner(): JSX.Element | null {
  const t = useTranslations('cookies');
  const [open, setOpen] = useState(false);

  useEffect(() => {
    const existing = readConsent();
    if (existing === null) {
      setOpen(true);
    }
  }, []);

  if (!open) return null;

  const onAccept = (): void => {
    writeConsent('granted');
    setOpen(false);
  };
  const onRefuse = (): void => {
    writeConsent('denied');
    setOpen(false);
  };

  return (
    <div
      role="dialog"
      aria-labelledby="cookie-banner-heading"
      aria-describedby="cookie-banner-body"
      data-testid="cookie-banner"
      className="fixed inset-x-3 bottom-3 z-50 mx-auto max-w-3xl rounded-lg border border-gray-200 bg-white p-5 shadow-2xl md:p-6"
    >
      <div className="grid gap-4 md:grid-cols-[1fr_auto] md:items-center">
        <div>
          <h2 id="cookie-banner-heading" className="font-heading text-lg font-bold text-[#6B2FA0]">
            {t('title')}
          </h2>
          <p id="cookie-banner-body" className="mt-2 text-sm text-[#555]">
            {t('body')}{' '}
            <a href="/confidentialite" className="underline hover:text-[#6B2FA0]">
              {t('learnMore')}
            </a>
            .
          </p>
        </div>
        <div className="flex flex-wrap gap-2 md:flex-nowrap">
          <button
            type="button"
            onClick={onRefuse}
            data-testid="cookie-refuse"
            className="inline-flex h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm text-[#333] hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
          >
            {t('refuse')}
          </button>
          <button
            type="button"
            onClick={onAccept}
            data-testid="cookie-accept"
            className="inline-flex h-11 items-center rounded-md bg-[#6B2FA0] px-4 text-sm font-medium text-white hover:bg-[#9B59B6] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
          >
            {t('accept')}
          </button>
        </div>
      </div>
    </div>
  );
}
