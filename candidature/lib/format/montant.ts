/** « 185 000 FCFA » (fr) ou « 185,000 FCFA » (en). */
export function formatFcfa(montant: number, locale: string): string {
  const nombre = new Intl.NumberFormat(locale === 'en' ? 'en-GB' : 'fr-FR', { maximumFractionDigits: 0 }).format(montant);
  return `${nombre} FCFA`;
}
