/**
 * Le backend renvoie des titres déjà échappés avec des <mark>. Défense en
 * profondeur : tout est ré-échappé, seuls `<mark>` et `</mark>` exacts sont rétablis.
 */
export function sanitizeHighlight(html: string): string {
  const escaped = html.replace(/&(?!(?:amp|lt|gt|quot|#39);)/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return escaped.replace(/&lt;mark&gt;/g, '<mark>').replace(/&lt;\/mark&gt;/g, '</mark>');
}
