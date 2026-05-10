import type { Config } from 'tailwindcss';
import { colors, fonts, fontSizes, spacing, breakpoints, radius } from './src/tokens';

const config: Pick<Config, 'darkMode' | 'theme' | 'plugins'> = {
  darkMode: 'class',
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
      fontSize: {
        ...fontSizes,
        // Hiérarchie typographique forte (PR Q § B.4)
        'pssfp-h1': ['clamp(2.25rem, 1.5rem + 3vw, 3.5rem)', { lineHeight: '1.05', letterSpacing: '-0.02em' }],
        'pssfp-h2': ['clamp(1.75rem, 1.25rem + 2vw, 2.5rem)', { lineHeight: '1.1', letterSpacing: '-0.015em' }],
        'pssfp-h3': ['clamp(1.25rem, 1rem + 1vw, 1.5rem)', { lineHeight: '1.2' }],
        'pssfp-body': ['clamp(1rem, 0.95rem + 0.25vw, 1.0625rem)', { lineHeight: '1.65' }],
        'pssfp-lead': ['clamp(1.125rem, 1rem + 0.5vw, 1.25rem)', { lineHeight: '1.55' }],
      },
      spacing,
      borderRadius: {
        ...radius,
        'pssfp-card': '1rem',
        'pssfp-button': '0.625rem',
      },
      screens: breakpoints,
      backgroundImage: {
        'gradient-violet':
          'linear-gradient(135deg, #6B2FA0 0%, #9B59B6 100%)',
        'gradient-violet-or':
          'linear-gradient(135deg, #6B2FA0 0%, #C9A227 100%)',
        'gradient-violet-deep':
          'linear-gradient(135deg, #4A1F70 0%, #6B2FA0 60%, #9B59B6 100%)',
        'gradient-lavande-blanc':
          'linear-gradient(180deg, #EDE7F6 0%, #FFFFFF 100%)',
        'gradient-or-soft':
          'linear-gradient(135deg, #C9A227 0%, #E8C868 100%)',
        // Editorial Senate (refonte) — purple est l'accent, pas le décor
        'gradient-ink-deep':
          'linear-gradient(135deg, #14101A 0%, #2A1E3A 60%, #3F2A55 100%)',
        'gradient-forest':
          'linear-gradient(135deg, #0E4D3F 0%, #1B6E5A 100%)',
        'gradient-forest-or':
          'linear-gradient(135deg, #0E4D3F 0%, #C9A227 100%)',
        'gradient-cream-warm':
          'linear-gradient(180deg, #FBF7EE 0%, #F4ECDC 100%)',
        'gradient-sand-cream':
          'linear-gradient(180deg, #EFE6CE 0%, #FBF7EE 100%)',
      },
      boxShadow: {
        // Shadows neutres/warm — moins de tint violet
        'pssfp-soft':
          '0 1px 2px 0 rgba(20, 16, 26, 0.04), 0 1px 3px 0 rgba(20, 16, 26, 0.06)',
        'pssfp-elevated':
          '0 4px 6px -1px rgba(20, 16, 26, 0.06), 0 10px 20px -3px rgba(20, 16, 26, 0.08), 0 2px 4px -2px rgba(20, 16, 26, 0.04)',
        'pssfp-floating':
          '0 12px 24px -6px rgba(20, 16, 26, 0.14), 0 24px 48px -12px rgba(20, 16, 26, 0.18)',
        'pssfp-glow-violet':
          '0 8px 24px -4px rgba(107, 47, 160, 0.30)',
        'pssfp-glow-or':
          '0 8px 24px -4px rgba(201, 162, 39, 0.35)',
        'pssfp-glow-forest':
          '0 8px 24px -4px rgba(14, 77, 63, 0.32)',
      },
      backdropBlur: {
        '2xs': '4px',
        '4xl': '40px',
      },
      keyframes: {
        'pssfp-fade-up': {
          '0%': { opacity: '0', transform: 'translateY(12px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        'pssfp-slide-in': {
          '0%': { opacity: '0', transform: 'translateX(-12px)' },
          '100%': { opacity: '1', transform: 'translateX(0)' },
        },
        'pssfp-pulse-violet': {
          '0%, 100%': { boxShadow: '0 0 0 0 rgba(155, 89, 182, 0.45)' },
          '50%': { boxShadow: '0 0 0 14px rgba(155, 89, 182, 0)' },
        },
        'pssfp-marquee': {
          '0%': { transform: 'translateX(0)' },
          '100%': { transform: 'translateX(calc(-100% - var(--gap, 1rem)))' },
        },
        'pssfp-marquee-vertical': {
          '0%': { transform: 'translateY(0)' },
          '100%': { transform: 'translateY(calc(-100% - var(--gap, 1rem)))' },
        },
        'pssfp-shimmer': {
          '0%': { backgroundPosition: '-200% 0' },
          '100%': { backgroundPosition: '200% 0' },
        },
      },
      animation: {
        'pssfp-fade-up': 'pssfp-fade-up 600ms cubic-bezier(0.16, 1, 0.3, 1) both',
        'pssfp-slide-in': 'pssfp-slide-in 400ms cubic-bezier(0.16, 1, 0.3, 1) both',
        'pssfp-pulse-violet': 'pssfp-pulse-violet 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
        'pssfp-marquee': 'pssfp-marquee var(--duration, 40s) linear infinite',
        'pssfp-marquee-vertical': 'pssfp-marquee-vertical var(--duration, 40s) linear infinite',
        'pssfp-shimmer': 'pssfp-shimmer 2s linear infinite',
      },
      transitionTimingFunction: {
        'pssfp-out-expo': 'cubic-bezier(0.16, 1, 0.3, 1)',
      },
    },
  },
  plugins: [],
};

export default config;
