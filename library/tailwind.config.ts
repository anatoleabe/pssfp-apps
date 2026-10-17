import type { Config } from 'tailwindcss';
import sharedTheme from '../packages/ui/tailwind.config';

/*
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

const config: Config = {
  content: [
    './app/**/*.{ts,tsx}',
    './components/**/*.{ts,tsx}',
    './lib/**/*.{ts,tsx}',
    '../packages/ui/src/**/*.{ts,tsx}',
  ],
  theme: { ...sharedTheme.theme, extend: { ...sharedTheme.theme?.extend, fontFamily } },
  plugins: sharedTheme.plugins,
};

export default config;
