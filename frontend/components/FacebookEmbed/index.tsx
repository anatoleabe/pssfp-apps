'use client';

import { useEffect, useState } from 'react';
import { useTranslations } from 'next-intl';

const STORAGE_KEY = 'pssfp_fb_consent_v1';

/**
 * Embed Facebook Page Plugin avec consentement RGPD explicite (cf. spec
 * module 1 PR N + CDC §10.4).
 *
 * Tant que l'utilisateur n'a pas cliqué « Charger le flux Facebook »,
 * aucun script tiers ni iframe n'est injecté. C'est un click-to-consent
 * minimaliste, alternative légère au Cookie Banner global pour ce widget
 * spécifique.
 *
 * Le consentement est persisté en localStorage. L'utilisateur peut le
 * révoquer en effaçant son storage (à câbler proprement plus tard avec
 * la bannière cookies).
 */
export function FacebookEmbed(): JSX.Element {
  const t = useTranslations('actualites.facebook');
  const [consented, setConsented] = useState(false);
  const [hydrated, setHydrated] = useState(false);

  useEffect(() => {
    setHydrated(true);
    try {
      setConsented(window.localStorage.getItem(STORAGE_KEY) === 'granted');
    } catch {
      // ignore
    }
  }, []);

  const giveConsent = (): void => {
    try {
      window.localStorage.setItem(STORAGE_KEY, 'granted');
    } catch {
      // ignore
    }
    setConsented(true);
  };

  if (!hydrated) {
    return <div data-testid="fb-embed-placeholder" className="h-64" />;
  }

  if (!consented) {
    return (
      <div
        role="region"
        aria-label="Flux Facebook officiel"
        data-testid="fb-embed-consent"
        className="rounded-lg border border-[#EDE7F6] bg-[#FAF7FF] p-6 text-center"
      >
        <p className="font-heading text-lg font-bold text-[#6B2FA0]">{t('title')}</p>
        <p className="mt-2 text-sm text-[#555]">{t('body')}</p>
        <button
          type="button"
          onClick={giveConsent}
          data-testid="fb-embed-consent-accept"
          className="mt-4 inline-flex h-11 items-center rounded-md bg-[#6B2FA0] px-5 text-sm font-medium text-white hover:bg-[#9B59B6] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
        >
          {t('accept')}
        </button>
      </div>
    );
  }

  return (
    <div
      data-testid="fb-embed-loaded"
      className="overflow-hidden rounded-lg border border-[#EDE7F6] bg-white"
    >
      {/* Page Plugin officiel Facebook chargé seulement après consentement.
          Pas de SDK JS — iframe statique, c'est largement suffisant pour
          afficher les derniers posts (cf. https://developers.facebook.com/docs/plugins/page-plugin). */}
      <iframe
        title="Flux Facebook officiel @pssfpcameroun"
        src="https://www.facebook.com/plugins/page.php?href=https%3A%2F%2Fwww.facebook.com%2Fpssfpcameroun&tabs=timeline&width=380&height=500&small_header=true&adapt_container_width=true&hide_cover=false&show_facepile=false&locale=fr_FR"
        width="100%"
        height="500"
        style={{ border: 'none', overflow: 'hidden' }}
        scrolling="no"
        loading="lazy"
        allow="encrypted-media"
      />
    </div>
  );
}
