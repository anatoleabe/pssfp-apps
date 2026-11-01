import { headers } from 'next/headers';

const IPV4 = /^(\d{1,3}\.){3}\d{1,3}$/;
const IPV6 = /^[0-9a-fA-F:]+$/;

/**
 * En-têtes qui transmettent à l'API l'adresse IP réelle du visiteur.
 *
 * Les appels d'authentification partent du serveur du portail : sans ces
 * en-têtes, l'API ne voit que 127.0.0.1 et ses limites « par IP »
 * (connexion, inscription, PIN oublié) s'appliquent à tous les candidats à la
 * fois. nginx pose `X-Real-IP` (adresse réelle, Cloudflare déjà résolu) ; le
 * secret partagé prouve à l'API que l'en-tête vient bien du portail.
 *
 * Hors requête HTTP (build, tests) ou sans secret configuré : aucun en-tête.
 */
export async function visiteurHeaders(): Promise<Record<string, string>> {
  const secret = process.env.PSSFP_PORTAIL_SECRET ?? '';
  if (secret === '') return {};

  try {
    const entrants = await headers();
    // X-Real-IP seul : nginx le réécrit toujours ; X-Forwarded-For peut venir du visiteur.
    const ip = (entrants.get('x-real-ip') ?? '').trim();
    if (!IPV4.test(ip) && !(ip.includes(':') && IPV6.test(ip))) return {};

    return { 'X-PSSFP-Client-IP': ip, 'X-PSSFP-Portail': secret };
  } catch {
    return {};
  }
}
