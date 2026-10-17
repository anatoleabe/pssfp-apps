const INTEGER = new Intl.NumberFormat('fr-FR');
const ONE_DECIMAL = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });

const KIB = 1024;
const MIB = KIB * 1024;

/** Prix en francs CFA, séparateur de milliers français (espace fine insécable). */
export function formatPrice(xaf: number): string {
  return INTEGER.format(xaf);
}

/** Taille lisible : valeur formatée + unité, le libellé de l'unité restant côté traductions. */
export function fileSizeParts(bytes: number): { value: string; unit: 'ko' | 'mo' } {
  if (bytes < MIB) return { value: INTEGER.format(Math.max(1, Math.round(bytes / KIB))), unit: 'ko' };
  return { value: ONE_DECIMAL.format(bytes / MIB), unit: 'mo' };
}
