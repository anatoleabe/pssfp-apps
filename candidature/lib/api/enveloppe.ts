/**
 * Contenu d'une réponse Laravel enveloppée dans `{ data: … }`.
 *
 * `{ data: null }` veut dire « rien à afficher » (aucun calendrier publié,
 * aucun mémoire) et doit rester `null` : rendre l'enveloppe à la place faisait
 * planter les pages de l'espace auditeur. Une réponse non enveloppée (jeton de
 * connexion, liste brute) est rendue telle quelle.
 */
export function contenuEnveloppe<T>(payload: unknown): T {
  if (payload !== null && typeof payload === 'object' && !Array.isArray(payload) && 'data' in payload) {
    return (payload as { data: T }).data;
  }

  return payload as T;
}
