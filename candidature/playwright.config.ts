import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:6003';

export default defineConfig({
  testDir: './tests/playwright',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    // Chromium annonce `en-US` par défaut : sans cette ligne, le middleware
    // servirait l'anglais et toutes les assertions françaises échoueraient.
    // La langue attendue par un test doit être déclarée, jamais subie.
    locale: 'fr-FR',
  },
  projects: [
    { name: 'chromium', testIgnore: /.*\.en\.spec\.ts/, use: { ...devices['Desktop Chrome'] } },
    {
      // Parcours anglophone : mêmes pages, navigateur annonçant `en-US`.
      name: 'chromium-en',
      testMatch: /.*\.en\.spec\.ts/,
      use: { ...devices['Desktop Chrome'], locale: 'en-US' },
    },
  ],
  webServer: process.env.PLAYWRIGHT_NO_SERVER
    ? undefined
    : {
        command: 'pnpm dev --port 6003',
        env: {
          ...process.env,
          PSSFP_E2E_OFFLINE: process.env.PSSFP_E2E_OFFLINE ?? '1',
        },
        url: BASE_URL,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
      },
});
