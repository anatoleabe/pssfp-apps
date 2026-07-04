'use client';

import { useEffect, useRef, useState } from 'react';
import { useTranslations } from 'next-intl';

const SITE_KEY = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY ?? '';
const SCRIPT_ID = 'cf-turnstile-script';
const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

/**
 * Le captcha n'est actif que si une clé publique est configurée. En dev/test
 * (clé absente), le widget ne s'affiche pas et les formulaires ne l'exigent
 * pas — le backend fait autorité (`TurnstileVerifier` bypass si pas de secret).
 */
export function isTurnstileEnabled(): boolean {
  return SITE_KEY.length > 0;
}

interface TurnstileApi {
  render: (el: HTMLElement, opts: Record<string, unknown>) => string;
  reset: (id?: string) => void;
  remove: (id: string) => void;
}

declare global {
  interface Window {
    turnstile?: TurnstileApi;
  }
}

let scriptPromise: Promise<void> | null = null;

function loadTurnstileScript(): Promise<void> {
  if (typeof window === 'undefined') {
    return Promise.resolve();
  }
  if (window.turnstile) {
    return Promise.resolve();
  }
  if (scriptPromise) {
    return scriptPromise;
  }

  scriptPromise = new Promise<void>((resolve, reject) => {
    // En cas d'échec (adblock, CSP, réseau) : on retire le tag et on ré-arme
    // scriptPromise à null pour qu'un « Réessayer » retente un vrai chargement.
    const fail = (el: HTMLScriptElement) => (): void => {
      scriptPromise = null;
      el.remove();
      reject(new Error('turnstile script failed'));
    };

    const existing = document.getElementById(SCRIPT_ID) as HTMLScriptElement | null;
    if (existing) {
      existing.addEventListener('load', () => resolve());
      existing.addEventListener('error', fail(existing));
      return;
    }
    const script = document.createElement('script');
    script.id = SCRIPT_ID;
    script.src = SCRIPT_SRC;
    script.async = true;
    script.defer = true;
    script.addEventListener('load', () => resolve());
    script.addEventListener('error', fail(script));
    document.head.appendChild(script);
  });

  return scriptPromise;
}

type TurnstileStatus = 'loading' | 'ready' | 'error';

interface TurnstileWidgetProps {
  /** Appelé avec le token à chaque résolution réussie du challenge. */
  onVerify: (token: string) => void;
  /** Appelé quand le token expire ou qu'une erreur survient (token invalidé). */
  onExpire?: () => void;
  /** Contexte d'usage transmis à Cloudflare (analytics anti-abus). */
  action?: string;
  /**
   * Incrémenter cette valeur force la régénération d'un token frais. Utile
   * après une soumission échouée : le token précédent est à usage unique et
   * déjà consommé côté siteverify.
   */
  resetKey?: number;
  className?: string;
}

export function TurnstileWidget({
  onVerify,
  onExpire,
  action,
  resetKey,
  className,
}: TurnstileWidgetProps): JSX.Element | null {
  const t = useTranslations('turnstile');
  const containerRef = useRef<HTMLDivElement | null>(null);
  const widgetIdRef = useRef<string | null>(null);
  const [status, setStatus] = useState<TurnstileStatus>('loading');
  // Message transitoire annoncé aux AT (expiration / erreur du challenge).
  const [announcement, setAnnouncement] = useState<string | null>(null);
  // Incrémenté par « Réessayer » pour relancer le chargement du script.
  const [loadAttempt, setLoadAttempt] = useState(0);
  // Références vivantes vers les callbacks : évite de re-render le widget
  // (coûteux + reset visuel) à chaque changement de closure parente.
  const onVerifyRef = useRef(onVerify);
  const onExpireRef = useRef(onExpire);
  onVerifyRef.current = onVerify;
  onExpireRef.current = onExpire;

  useEffect(() => {
    if (!SITE_KEY) {
      return;
    }
    let cancelled = false;
    setStatus('loading');

    loadTurnstileScript()
      .then(() => {
        if (cancelled || !containerRef.current || !window.turnstile) {
          return;
        }
        widgetIdRef.current = window.turnstile.render(containerRef.current, {
          sitekey: SITE_KEY,
          action,
          callback: (token: string) => {
            setAnnouncement(null);
            onVerifyRef.current(token);
          },
          'expired-callback': () => {
            setAnnouncement(t('expired'));
            onExpireRef.current?.();
          },
          'error-callback': () => {
            setAnnouncement(t('expired'));
            onExpireRef.current?.();
          },
          'timeout-callback': () => onExpireRef.current?.(),
        });
        setStatus('ready');
      })
      .catch(() => {
        // Script bloqué (CSP / adblock / réseau) : état perceptible + retry.
        // Le bouton de soumission du parent reste désactivé tant qu'aucun
        // token n'existe — sans ce message, le parcours serait verrouillé
        // en silence (WCAG 3.3.1 / 4.1.3).
        if (!cancelled) {
          setStatus('error');
        }
      });

    return () => {
      cancelled = true;
      if (widgetIdRef.current && window.turnstile) {
        try {
          window.turnstile.remove(widgetIdRef.current);
        } catch {
          // widget déjà retiré : ignore
        }
        widgetIdRef.current = null;
      }
    };
  }, [action, loadAttempt, t]);

  // Régénère un token frais quand le parent incrémente resetKey (post-échec).
  useEffect(() => {
    if (!resetKey) {
      return;
    }
    if (widgetIdRef.current && window.turnstile) {
      try {
        window.turnstile.reset(widgetIdRef.current);
      } catch {
        // widget non prêt : ignore
      }
    }
  }, [resetKey]);

  if (!SITE_KEY) {
    return null;
  }

  return (
    <div className={className}>
      <div ref={containerRef} data-testid="turnstile-widget" />
      {status === 'error' && (
        <div
          role="alert"
          data-testid="turnstile-error"
          className="mt-2 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700"
        >
          <p>{t('loadError')}</p>
          <button
            type="button"
            onClick={() => setLoadAttempt((n) => n + 1)}
            className="mt-2 rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100"
          >
            {t('retry')}
          </button>
        </div>
      )}
      <p aria-live="polite" className={announcement ? 'mt-2 text-sm text-amber-800' : 'sr-only'}>
        {announcement}
      </p>
    </div>
  );
}
