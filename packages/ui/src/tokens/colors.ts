export const colors = {
  primary: '#6B2FA0',
  primaryHover: '#9B59B6',
  surface: '#EDE7F6',
  accent: '#C9A227',
  background: '#FFFFFF',
  text: '#333333',
  textMuted: '#666666',
  surfaceAlt: '#F5F5F5',
  success: '#2E7D32',
  warning: '#F9A825',
  error: '#C62828',
  info: '#1565C0',
} as const;

export type ColorToken = keyof typeof colors;
