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
 * Les noms composés sont entre guillemets : Tailwind les recopie tels quels et
 * `Source Sans 3` non guillemeté invalide toute la déclaration.
 * (Le même correctif arrive dans le preset partagé par la PR #44 ; cette
 * surcharge pourra alors être retirée.)
 */
const fontFamily = {
  heading: ['var(--font-heading)', '"Cormorant Garamond"', 'Georgia', 'serif'],
  body: ['var(--font-body)', '"Source Sans 3"', 'system-ui', 'sans-serif'],
  ui: ['var(--font-ui)', '"DM Sans"', 'system-ui', 'sans-serif'],
};

/*
 * Or foncé #7A5A1C : version lisible de l'or champagne pour du texte sur fond clair
 * (5,9:1 sur ivoire, 6,9:1 sur blanc, 5,42:1 sur #F6ECD9) ; le #D4AF6A plafonne à ~2:1.
 */
/*
 * Prune « deep » et « ink » : nuances plus sombres de la prune profonde #3A2452, réservées aux
 * surfaces sombres (héros, bandeau institutionnel, pied de page) — ADR-0008 autorise la prune
 * profonde et ses nuances plus sombres pour ces surfaces. Contrastes : blanc / deep 15,5:1 ·
 * blanc 70 % / deep 8,3:1 · blanc / ink 17,6:1 · lavande #A592BD / ink 6,2:1 · lavande / deep 5,5:1 ·
 * or #D4AF6A / deep 7,5:1. La lavande n'est jamais un texte sur fond clair (2,8:1 sur blanc).
 * « Mist » : lavande très claire des surfaces secondaires (prune #4A2E67 dessus : 9,9:1 ;
 * graphite #3C3C3C : 9,8:1 ; graphite clair #6B6B6B : 4,7:1 ; or foncé #7A5A1C : 5,6:1).
 */
const sharedColors = (sharedTheme.theme?.extend?.colors ?? {}) as Record<string, unknown>;
const colors = {
  ...sharedColors,
  'pssfp-prune': { ...(sharedColors['pssfp-prune'] as Record<string, string>), deep: '#2E1C40', ink: '#211429' },
  'pssfp-or': { ...(sharedColors['pssfp-or'] as Record<string, string>), dark: '#7A5A1C' },
  'pssfp-mist': '#F4F0F8',
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
