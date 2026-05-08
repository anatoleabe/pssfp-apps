import type { Config } from 'tailwindcss';
import { colors, fonts, fontSizes, spacing, breakpoints, radius } from './src/tokens';

const config: Pick<Config, 'theme' | 'plugins'> = {
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: colors.primary,
          hover: colors.primaryHover,
        },
        surface: colors.surface,
        'surface-alt': colors.surfaceAlt,
        accent: colors.accent,
        'text-muted': colors.textMuted,
        success: colors.success,
        warning: colors.warning,
        error: colors.error,
        info: colors.info,
      },
      fontFamily: {
        heading: fonts.heading.split(',').map((s) => s.trim()),
        body: fonts.body.split(',').map((s) => s.trim()),
        ui: fonts.ui.split(',').map((s) => s.trim()),
      },
      fontSize: fontSizes,
      spacing,
      borderRadius: radius,
      screens: breakpoints,
    },
  },
  plugins: [],
};

export default config;
