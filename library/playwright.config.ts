import { createRequire } from 'node:module';
import { defineConfig, devices } from '@playwright/test';

// Charge .env.local & co. comme `next dev` : les tests lisent la même NEXT_PUBLIC_API_URL que l'app.
const requireFromNext = createRequire(require.resolve('next/package.json'));
const { loadEnvConfig } = requireFromNext('@next/env') as { loadEnvConfig: (dir: string, dev?: boolean) => unknown };
loadEnvConfig(__dirname, true);

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:6002';

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
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: process.env.PLAYWRIGHT_NO_SERVER
    ? undefined
    : {
        // Cache de données vidé au démarrage : des réponses mises en cache lors d'un passage avec
        // backend, devenues périmées, déclenchent sinon des revalidations en échec (API coupée)
        // qui font parfois échouer le rendu en dev (Next 14.2 : « failed to pipe response »).
        command: `node -e "require('fs').rmSync('.next/cache/fetch-cache',{recursive:true,force:true})" && pnpm dev --port 6002`,
        url: BASE_URL,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
      },
});
