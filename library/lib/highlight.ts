/**
 * Le backend renvoie des titres déjà échappés (Laravel `e()`, htmlspecialchars
 * ENT_QUOTES : `&#039;` pour l'apostrophe) avec des <mark>. Défense en profondeur :
 * tout est ré-échappé — les entités déjà valides (nommées ou numériques) sont
 * conservées — puis seuls `<mark>` et `</mark>` exacts sont rétablis, sans attribut.
 */
const EXISTING_ENTITY = /&(?!(?:amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);)/g;

export function sanitizeHighlight(html: string): string {
  const escaped = html.replace(EXISTING_ENTITY, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return escaped.replace(/&lt;mark&gt;/g, '<mark>').replace(/&lt;\/mark&gt;/g, '</mark>');
}
