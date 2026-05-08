export const fonts = {
  heading: 'Playfair Display, serif',
  body: 'Inter, sans-serif',
  ui: 'DM Sans, sans-serif',
} as const;

export const fontSizes = {
  xs: '0.75rem',
  sm: '0.875rem',
  base: '1rem',
  lg: '1.125rem',
  xl: '1.25rem',
  '2xl': '1.5rem',
  '3xl': '1.875rem',
  '4xl': '2.25rem',
  '5xl': '3rem',
  '6xl': '3.75rem',
} as const;

export const fontWeights = {
  regular: 400,
  medium: 500,
  semibold: 600,
  bold: 700,
} as const;

export type FontFamily = keyof typeof fonts;
export type FontSize = keyof typeof fontSizes;
export type FontWeight = keyof typeof fontWeights;
