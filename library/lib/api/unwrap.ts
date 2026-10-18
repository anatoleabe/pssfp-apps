import { notFound } from 'next/navigation';
import type { ApiResult } from './types';

/** Message de l'erreur levée quand l'API ne répond pas (réseau, délai, 5xx, 4xx autre que 404). */
export const API_UNAVAILABLE = 'library-api-unavailable';

/** Valeur de `process.env.NEXT_PHASE` pendant `next build` (`PHASE_PRODUCTION_BUILD` de `next/constants`). */
const PRODUCTION_BUILD_PHASE = 'phase-production-build';

/**
 * Pages ISR : une donnée indispensable absente ne doit jamais être mise en cache.
 * - ok → données ;
 * - 404 → `notFound()` ;
 * - autre échec → exception : pendant une revalidation ISR, Next conserve la dernière page
 *   valide ; au premier rendu, l'erreur atteint `app/error.tsx`.
 */
export function unwrapOrThrow<T>(result: ApiResult<T>): T {
  if (result.ok) return result.data;
  if (result.error.status === 404) notFound();
  throw new Error(API_UNAVAILABLE);
}

/**
 * Vrai pendant le prérendu de `next build`. Une exception y ferait échouer tout le build
 * (la CI construit sans API) : les pages rendent alors une page de repli, que l'ISR
 * remplace dès la première revalidation réussie.
 */
export function isBuildPhase(): boolean {
  return process.env.NEXT_PHASE === PRODUCTION_BUILD_PHASE;
}
