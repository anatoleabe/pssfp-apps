'use client';

import { useEffect, useState } from 'react';

const STORAGE_KEY = 'pssfp_maps_consent_v1';

/**
 * Embed Google Maps avec consentement RGPD click-to-load (cf. spec module 1
 * PR O). Tant que l'utilisateur n'a pas accepté, aucune iframe Google n'est
 * chargée — même approche que FacebookEmbed.
 *
 * Coordonnées Campus de Messa, Yaoundé : approximation publique.
 */
export function GoogleMapEmbed(): JSX.Element {
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
    return <div data-testid="map-placeholder" className="h-72" />;
  }

  if (!consented) {
    return (
      <div
        role="region"
        aria-label="Carte Google Maps Campus de Messa"
        data-testid="map-consent"
        className="rounded-lg border border-[#EDE7F6] bg-[#FAF7FF] p-6 text-center"
      >
        <p className="font-heading text-lg font-bold text-[#6B2FA0]">
          Localisation — Campus de Messa
        </p>
        <p className="mt-2 text-sm text-[#555]">
          Cliquez pour charger la carte Google Maps. Aucun cookie tiers Google n'est déposé tant que vous n'avez pas accepté.
        </p>
        <button
          type="button"
          onClick={giveConsent}
          data-testid="map-consent-accept"
          className="mt-4 inline-flex h-11 items-center rounded-md bg-[#6B2FA0] px-5 text-sm font-medium text-white hover:bg-[#9B59B6] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
        >
          Charger la carte
        </button>
      </div>
    );
  }

  return (
    <div data-testid="map-loaded" className="overflow-hidden rounded-lg border border-[#EDE7F6]">
      <iframe
        title="Carte Google Maps — Campus de Messa, Yaoundé"
        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3979.4!2d11.515!3d3.857!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zM8KwNTEnMjUuMiJOIDExwrAzMCc1NC4wIkU!5e0!3m2!1sfr!2scm!4v1700000000"
        width="100%"
        height="320"
        style={{ border: 'none' }}
        loading="lazy"
        allowFullScreen
        referrerPolicy="no-referrer-when-downgrade"
      />
    </div>
  );
}
