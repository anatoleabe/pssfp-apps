/**
 * Signalement d'un écran d'erreur au back-office (page « Erreurs techniques »).
 *
 * Le navigateur poste au relais du portail (`/auth/signaler-erreur`), qui
 * ajoute le compte connecté et l'adresse réelle du visiteur avant de
 * transmettre à l'API. Signaler ne doit jamais aggraver l'erreur : tout échec
 * est ignoré.
 */

export const MESSAGE_MAX = 1000;
export const PAGE_MAX = 500;
export const DIGEST_MAX = 64;

export interface Signalement {
  page: string;
  message: string;
  digest?: string;
}

/**
 * Adresse sans paramètres ni ancre (un code d'accès peut y figurer), jeton du
 * lien de la lettre d'accès masqué (/l/{jeton}), message borné.
 */
export function construireSignalement(erreur: Error & { digest?: string }, adresse: string): Signalement {
  const page = (adresse.split(/[?#]/)[0] ?? '').replace(/\/l\/[^/]+/, '/l/{jeton}').slice(0, PAGE_MAX);
  const message = (erreur.message || erreur.name || 'Erreur').slice(0, MESSAGE_MAX);

  return erreur.digest ? { page, message, digest: erreur.digest.slice(0, DIGEST_MAX) } : { page, message };
}

export function signalerErreur(erreur: Error & { digest?: string }): void {
  if (typeof window === 'undefined') return;
  try {
    void fetch('/auth/signaler-erreur', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(construireSignalement(erreur, window.location.href)),
      keepalive: true,
    }).catch(() => undefined);
  } catch {
    // Le signalement est un bonus : jamais une seconde erreur.
  }
}
