import type { Config } from 'tailwindcss';
import sharedTheme from '../packages/ui/tailwind.config';

const config: Config = {
  content: [
    './app/**/*.{ts,tsx}',
    './components/**/*.{ts,tsx}',
    './lib/**/*.{ts,tsx}',
    '../packages/ui/src/**/*.{ts,tsx}',
  ],
  darkMode: sharedTheme.darkMode,
  theme: sharedTheme.theme,
  plugins: sharedTheme.plugins,
};

export default config;
