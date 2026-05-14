/**
 * Charte 2026 PSSFP — ADR-0008 (2026-05-14)
 * Remplace la palette CDC v5 §10.1 d'origine.
 */
export const colors = {
  // Primary — Prune institutionnelle
  primary: '#4A2E67',
  primaryHover: '#3A2452',
  primaryLight: '#5C3A7E',
  // Secondary — Bleu pétrole (NOUVEAU, 2e accent autorité)
  secondary: '#0F3A4A',
  secondaryHover: '#082A37',
  // Tertiary — Lavande grisée
  tertiary: '#A592BD',
  surface: '#A592BD',
  // Accent — Or champagne
  accent: '#D4AF6A',
  accentHover: '#E5C788',
  // Surfaces
  background: '#FFFFFF',
  ivoire: '#FAF7F2',
  surfaceAlt: '#F4F0EA',
  // Textes
  text: '#3C3C3C',
  textStrong: '#1A1A1A',
  textMuted: '#6B6B6B',
  // États sémantiques
  success: '#2E7D32',
  warning: '#F9A825',
  error: '#C62828',
  info: '#1565C0',
} as const;

export type ColorToken = keyof typeof colors;
