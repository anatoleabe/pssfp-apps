import type { Config } from 'tailwindcss';
import sharedTheme from '../packages/ui/tailwind.config';

/*
 * Surcharge LOCALE du preset @pssfp/ui, volontairement limitée à la bibliothèque :
 * modifier le preset partagé changerait le rendu du site principal en production.
 *
 * next/font enregistre les polices sous un nom haché exposé par les variables
 * --font-heading / --font-body / --font-ui (app/layout.tsx). Le preset partagé nomme
 * les familles en clair (« Cormorant Garamond »…), qui ne correspondent à aucune
 * @font-face chargée : sans la variable en tête, les titres retombent sur Georgia.
 */
const fontFamily = {
  heading: ['var(--font-heading)', 'Cormorant Garamond', 'Georgia', 'serif'],
  body: ['var(--font-body)', 'Source Sans 3', 'system-ui', 'sans-serif'],
  ui: ['var(--font-ui)', 'DM Sans', 'system-ui', 'sans-serif'],
};

/*
 * Or foncé #7A5A1C : version lisible de l'or champagne pour du texte sur fond clair
 * (5,9:1 sur ivoire, 6,9:1 sur blanc, 5,42:1 sur #F6ECD9) ; le #D4AF6A plafonne à ~2:1.
 */
const sharedColors = (sharedTheme.theme?.extend?.colors ?? {}) as Record<string, unknown>;
const colors = {
  ...sharedColors,
  'pssfp-or': { ...(sharedColors['pssfp-or'] as Record<string, string>), dark: '#7A5A1C' },
};

const config: Config = {
  content: [
    './app/**/*.{ts,tsx}',
    './components/**/*.{ts,tsx}',
    './lib/**/*.{ts,tsx}',
    '../packages/ui/src/**/*.{ts,tsx}',
  ],
  theme: { ...sharedTheme.theme, extend: { ...sharedTheme.theme?.extend, colors, fontFamily } },
  plugins: sharedTheme.plugins,
};

export default config;
