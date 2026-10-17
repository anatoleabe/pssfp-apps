const KIB = 1024;
const MIB = KIB * 1024;

/**
 * Prix en francs CFA (XAF), sans décimales : à passer au formateur next-intl
 * (`format.number(price, PRICE_FORMAT)`). La devise vient d'Intl (« 2 500 FCFA » en fr-FR),
 * jamais des messages.
 */
export const PRICE_FORMAT = { style: 'currency', currency: 'XAF', maximumFractionDigits: 0 } as const satisfies Intl.NumberFormatOptions;

/**
 * Taille de fichier lisible : valeur et options d'unité pour le formateur next-intl
 * (`format.number(value, format)` → « 200 ko », « 2,4 Mo » en fr-FR).
 */
export interface FileSizeFormat {
  value: number;
  format: { style: 'unit'; unit: 'kilobyte' | 'megabyte'; maximumFractionDigits: number };
}

export function fileSizeFormat(bytes: number): FileSizeFormat {
  if (bytes < MIB) return { value: Math.max(1, Math.round(bytes / KIB)), format: { style: 'unit', unit: 'kilobyte', maximumFractionDigits: 0 } };
  return { value: bytes / MIB, format: { style: 'unit', unit: 'megabyte', maximumFractionDigits: 1 } };
}
