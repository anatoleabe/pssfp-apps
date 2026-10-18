/**
 * JSON-LD pour une balise `<script>` inline : JSON.stringify puis échappement de « < »
 * (`<`, toujours du JSON valide) ; aucune valeur ne peut fermer la balise par `</script>`.
 */
export function serializeJsonLd(data: Record<string, unknown>): string {
  return JSON.stringify(data).replace(/</g, '\\u003c');
}
