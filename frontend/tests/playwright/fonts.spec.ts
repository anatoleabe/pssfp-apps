import { test, expect } from '@playwright/test';

/*
 * Charte ADR-0008 : titres en Cormorant Garamond, corps en Source Sans 3.
 * next/font enregistre les polices sous un nom haché exposé par des variables
 * CSS ; si les classes Tailwind ne passent pas par ces variables, aucune
 * police web n'est chargée et le site s'affiche en Georgia / police système.
 */
async function firstFamilyIsLoaded(page: import('@playwright/test').Page, selector: string): Promise<{ family: string; loaded: boolean }> {
  return page.evaluate(async (sel) => {
    await document.fonts.ready;
    const el = document.querySelector(sel);
    if (!el) return { family: '(absent)', loaded: false };
    const family = getComputedStyle(el).fontFamily.split(',')[0]!.trim().replace(/["']/g, '');
    const loaded = [...document.fonts].some((f) => f.status === 'loaded' && f.family.replace(/["']/g, '') === family);
    return { family, loaded };
  }, selector);
}

test('les titres et le texte courant utilisent les polices web de la charte', async ({ page }) => {
  await page.goto('/');
  const heading = await firstFamilyIsLoaded(page, 'h1');
  const body = await firstFamilyIsLoaded(page, 'body');

  expect(heading.loaded, `police du h1 non chargée : ${heading.family}`).toBe(true);
  expect(heading.family).toMatch(/Cormorant/i);
  expect(body.loaded, `police du corps non chargée : ${body.family}`).toBe(true);
  expect(body.family).toMatch(/Source/i);
});
