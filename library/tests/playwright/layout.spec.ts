import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.describe('Socle', () => {
  test('lien d\'évitement, en-tête, pied de page et langue', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    await page.keyboard.press('Tab');
    const skip = page.getByRole('link', { name: /aller au contenu/i });
    await expect(skip).toBeFocused();
    await expect(page.getByRole('banner')).toBeVisible();
    await expect(page.getByRole('contentinfo')).toBeVisible();
    await expect(page.getByRole('link', { name: /pssfp\.org|site du pssfp/i }).first()).toBeVisible();
  });

  test('navigation principale : cinq rubriques, Thèmes vers /themes, Accueil courant sur /', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/a-propos');
    const nav = page.getByRole('navigation', { name: 'Navigation principale' });
    const links = nav.getByRole('link');
    await expect(links).toHaveText(['Accueil', 'Collections', 'Nouveautés', 'Thèmes', 'À propos']);
    await expect(nav.getByRole('link', { name: 'Thèmes' })).toHaveAttribute('href', '/themes');
    await expect(nav.getByRole('link', { name: 'Nouveautés' })).toHaveAttribute('href', '/#nouveautes');
    await expect(nav.getByRole('link', { name: 'À propos' })).toHaveAttribute('aria-current', 'page');
    await expect(nav.getByRole('link', { name: 'Accueil' })).not.toHaveAttribute('aria-current', /.*/);
    // Recherche : bouton dédié de l'en-tête.
    await expect(page.getByTestId('header-search')).toHaveAttribute('href', '/recherche');
  });

  test('logo officiel du PSSFP dans l\'en-tête et le pied de page', async ({ page }) => {
    await page.goto('/a-propos');
    const headerLogo = page.getByRole('banner').locator('img');
    await expect(headerLogo).toHaveAttribute('alt', 'PSSFP');
    await expect(headerLogo).toHaveAttribute('src', /logos%2Fpssfp\.png|logos\/pssfp\.png/);
    await expect(page.getByRole('contentinfo').locator('img')).toHaveAttribute('src', /logos%2Fpssfp\.png|logos\/pssfp\.png/);
  });

  test('bandeau institutionnel : site du PSSFP et FOAD', async ({ page }) => {
    await page.goto('/a-propos');
    const bar = page.getByRole('navigation', { name: 'Liens institutionnels' });
    await expect(bar.getByRole('link', { name: /site du pssfp/i })).toHaveAttribute('href', /^https?:\/\//);
    await expect(bar.getByRole('link', { name: /plateforme foad/i })).toHaveAttribute('href', /^https?:\/\/.*foad/);
  });

  test('tiroir : rubriques, Thèmes et recherche', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 800 });
    await page.goto('/a-propos');
    await page.getByRole('button', { name: /ouvrir le menu/i }).click();
    const drawer = page.getByRole('dialog');
    await expect(drawer.getByRole('link', { name: 'Thèmes' })).toHaveAttribute('href', '/themes');
    await expect(drawer.getByRole('link', { name: 'Recherche' })).toHaveAttribute('href', '/recherche');
    await expect(drawer.getByRole('link', { name: 'À propos' })).toHaveAttribute('aria-current', 'page');
  });

  test('menu mobile accessible au clavier', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto('/');
    const burger = page.getByRole('button', { name: /menu/i });
    await expect(burger).toHaveAttribute('aria-expanded', 'false');
    await burger.click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(burger).toBeFocused();
  });

  test('tiroir : Tab et Maj+Tab restent dans le dialogue', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto('/');
    await page.getByRole('button', { name: /ouvrir le menu/i }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    const focusInDialog = (): Promise<boolean> =>
      page.evaluate(() => !!document.activeElement?.closest('[role="dialog"]'));
    for (let i = 0; i < 8; i += 1) {
      await page.keyboard.press('Tab');
      expect(await focusInDialog()).toBe(true);
    }
    for (let i = 0; i < 8; i += 1) {
      await page.keyboard.press('Shift+Tab');
      expect(await focusInDialog()).toBe(true);
    }
  });

  test('tiroir : défilement verrouillé puis rétabli, fermeture par le fond', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto('/');
    const bodyOverflow = (): Promise<string> => page.evaluate(() => document.body.style.overflow);
    expect(await bodyOverflow()).toBe('');
    const burger = page.getByRole('button', { name: /ouvrir le menu/i });
    await burger.click();
    await expect(page.getByRole('dialog')).toBeVisible();
    expect(await bodyOverflow()).toBe('hidden');
    await page.getByTestId('mobile-menu-backdrop').click({ position: { x: 10, y: 300 } });
    await expect(page.getByRole('dialog')).toBeHidden();
    expect(await bodyOverflow()).toBe('');
    await expect(burger).toBeFocused();
  });

  test('tiroir : un lien ferme le menu sans renvoyer le focus au bouton', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto('/');
    const burger = page.getByRole('button', { name: /ouvrir le menu/i });
    await burger.click();
    await page.getByRole('dialog').getByRole('link', { name: 'Collections' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();
    await page.waitForTimeout(100);
    await expect(burger).not.toBeFocused();
  });

  test('bouton du menu : aria-controls seulement quand le tiroir est ouvert', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto('/a-propos');
    const burger = page.getByTestId('nav-toggle');
    await expect(burger).not.toHaveAttribute('aria-controls', /.*/);
    await burger.click();
    await expect(burger).toHaveAttribute('aria-controls', 'mobile-menu');
    await expect(page.locator('#mobile-menu')).toBeVisible();
  });

  test('tiroir ouvert puis fenêtre élargie : fermé, défilement rétabli, Tab non piégé', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto('/a-propos');
    const burger = page.getByTestId('nav-toggle');
    await burger.click();
    await expect(page.getByRole('dialog')).toBeVisible();
    expect(await page.evaluate(() => document.body.style.overflow)).toBe('hidden');

    await page.setViewportSize({ width: 1024, height: 768 });
    await expect(page.getByTestId('mobile-menu')).toHaveCount(0);
    expect(await page.evaluate(() => document.body.style.overflow)).toBe('');
    // Le bouton du menu est masqué (md:hidden) : le focus ne lui est pas rendu.
    await expect(burger).not.toBeFocused();
    // Tab et Maj+Tab parcourent la page : focus visible, hors de tout dialogue ; l'en-tête est atteignable.
    const where = (): Promise<{ inDialog: boolean; visible: boolean; inNav: boolean }> =>
      page.evaluate(() => {
        const a = document.activeElement as HTMLElement | null;
        return {
          inDialog: !!a?.closest('[role="dialog"]'),
          visible: !!a && a !== document.body && a.getClientRects().length > 0,
          inNav: !!a?.closest('nav[aria-label="Navigation principale"]'),
        };
      });
    await page.keyboard.press('Tab');
    expect(await where()).toMatchObject({ inDialog: false, visible: true });
    let reachedNav = false;
    for (let i = 0; i < 20 && !reachedNav; i += 1) {
      await page.keyboard.press('Shift+Tab');
      const w = await where();
      expect(w.inDialog).toBe(false);
      reachedNav = w.inNav;
    }
    expect(reachedNav).toBe(true);
  });

  test('scroll-padding-top couvre l\'en-tête collant (cible d\'ancre non masquée)', async ({ page }) => {
    for (const width of [360, 1280]) {
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/a-propos');
      const { padding, header } = await page.evaluate(() => ({
        padding: parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop),
        header: document.querySelector('[data-testid="site-header"]')?.getBoundingClientRect().height ?? 0,
      }));
      expect(header, `${width} px`).toBeGreaterThan(0);
      expect(padding, `${width} px`).toBeGreaterThanOrEqual(header);
      expect(padding - header, `${width} px`).toBeLessThan(16);
    }
  });

  test('aucun défilement horizontal à 320 px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await page.goto('/');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });

  test('404 en français, sans violation axe grave', async ({ page }) => {
    const res = await page.goto('/une-page-qui-n-existe-pas');
    expect(res?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });
});
