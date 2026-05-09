/**
 * Vérifie qu'une signature numérique tapée correspond bien à `${prenom} ${nom}`,
 * tolérante aux accents et espaces (P-min-4 PR E).
 *
 * Exemples :
 *   isValidEngagement('jean dupont', 'Jean', 'Dupont') -> true
 *   isValidEngagement('Jean DUPONT', 'Jean', 'Dupont') -> true
 *   isValidEngagement('Jéàn  Dupont', 'Jean', 'Dupont') -> true (NFD normalise)
 *   isValidEngagement('Jean', 'Jean', 'Dupont') -> false (nom manquant)
 */
export function normalizeName(s: string): string {
  return s
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

export function isValidEngagement(typed: string, prenom: string, nom: string): boolean {
  if (!typed || !prenom || !nom) {
    return false;
  }
  return normalizeName(typed) === normalizeName(`${prenom} ${nom}`);
}
