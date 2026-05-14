import type { Config } from 'tailwindcss';
import { colors, fonts, fontSizes, spacing, breakpoints, radius } from './src/tokens';

/**
 * Charte 2026 PSSFP — ADR-0008.
 * Tokens prune institutionnelle / bleu pétrole / or champagne / Cormorant Garamond / Source Sans 3.
 */
const config: Pick<Config, 'darkMode' | 'theme' | 'plugins'> = {
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: colors.primary,
          hover: colors.primaryHover,
          light: colors.primaryLight,
        },
        secondary: {
          DEFAULT: colors.secondary,
          hover: colors.secondaryHover,
        },
        // PSSFP-prefixed tokens — usage direct via class `bg-pssfp-prune`, `text-pssfp-or`, etc.
        'pssfp-prune': {
          DEFAULT: '#4A2E67',
          dark: '#3A2452',
          light: '#5C3A7E',
        },
        'pssfp-bleu-petrole': {
          DEFAULT: '#0F3A4A',
          dark: '#082A37',
        },
        'pssfp-lavande': '#A592BD',
        'pssfp-or': {
          DEFAULT: '#D4AF6A',
          light: '#E5C788',
        },
        'pssfp-ivoire': '#FAF7F2',
        'pssfp-graphite': {
          DEFAULT: '#3C3C3C',
          light: '#6B6B6B',
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
        // Prune
        'gradient-prune':
          'linear-gradient(135deg, #4A2E67 0%, #5C3A7E 100%)',
        'gradient-prune-deep':
          'linear-gradient(135deg, #3A2452 0%, #4A2E67 60%, #5C3A7E 100%)',
        'gradient-prune-lavande':
          'linear-gradient(135deg, #4A2E67 0%, #A592BD 100%)',
        // Combinés autorité
        'gradient-prune-petrole':
          'linear-gradient(135deg, #4A2E67 0%, #0F3A4A 100%)',
        'gradient-petrole-prune':
          'linear-gradient(135deg, #0F3A4A 0%, #4A2E67 100%)',
        // Or champagne
        'gradient-or':
          'linear-gradient(135deg, #D4AF6A 0%, #E5C788 100%)',
        'gradient-prune-or':
          'linear-gradient(135deg, #4A2E67 0%, #D4AF6A 100%)',
        'gradient-petrole-or':
          'linear-gradient(135deg, #0F3A4A 0%, #D4AF6A 100%)',
        // Ivoire / lavande
        'gradient-lavande-blanc':
          'linear-gradient(180deg, rgba(165, 146, 189, 0.18) 0%, #FFFFFF 100%)',
        'gradient-ivoire':
          'linear-gradient(180deg, #FAF7F2 0%, #FFFFFF 100%)',
        // Hero institutionnel sombre
        'gradient-ink-deep':
          'linear-gradient(135deg, #14101A 0%, #2A1E3A 60%, #4A2E67 100%)',
      },
      boxShadow: {
        'pssfp-soft':
          '0 1px 2px 0 rgba(60, 60, 60, 0.04), 0 1px 3px 0 rgba(60, 60, 60, 0.06)',
        'pssfp-elevated':
          '0 4px 6px -1px rgba(60, 60, 60, 0.06), 0 10px 20px -3px rgba(60, 60, 60, 0.08), 0 2px 4px -2px rgba(60, 60, 60, 0.04)',
        'pssfp-floating':
          '0 12px 24px -6px rgba(60, 60, 60, 0.14), 0 24px 48px -12px rgba(60, 60, 60, 0.18)',
        'pssfp-glow-prune':
          '0 8px 24px -4px rgba(74, 46, 103, 0.30)',
        'pssfp-glow-or':
          '0 8px 24px -4px rgba(212, 175, 106, 0.35)',
        'pssfp-glow-petrole':
          '0 8px 24px -4px rgba(15, 58, 74, 0.32)',
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
        'pssfp-pulse-prune': {
          '0%, 100%': { boxShadow: '0 0 0 0 rgba(92, 58, 126, 0.45)' },
          '50%': { boxShadow: '0 0 0 14px rgba(92, 58, 126, 0)' },
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
        'pssfp-pulse-prune': 'pssfp-pulse-prune 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
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
