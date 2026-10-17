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
