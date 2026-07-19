import { test, expect } from '@playwright/test';

/**
 * Tests Sprint S5.2 (#46) — vérifie que les cards `/actualites` affichent
 * de vraies photos et pas un placeholder à fond plat vide.
 *
 * Cause racine du bug : les articles non épinglés n'avaient pas de
 * `featured_image_path` en BDD (seul les épinglés en avaient via le
 * seeder S5 PR Z). Le frontend tombait sur le fallback à fond plat.
 *
 * Fix S5.2 :
 *   1. Backend — `ArticlesSeeder` complète `featured_image_path` pour
 *      les 6 articles legacy (photos de la collection MinIO existante).
 *   2. Frontend — fallback amélioré : si jamais un article n'a pas
 *      d'image (ex. Filament-CMS sans upload), fond plat prune
 *      + icône thématique + label catégorie, pas un placeholder
 *      vide qui fait pauvre.
 */

test.describe('/actualites — photos des cards (S5.2 #46)', () => {
  test('la 1re card affiche une vraie image MinIO (pas placeholder)', async ({ page }) => {
    await page.goto('/actualites');
    const firstCard = page.locator('[data-testid^="actualite-card-"]').first();
    await expect(firstCard).toBeVisible();

    // L'attribut `data-has-image="true"` est posé sur le wrapper de l'image
    // côté frontend (cf. actualites/page.tsx). Permet de distinguer image
    // réelle vs fallback éditorial.
    const imageContainer = firstCard.locator('[data-has-image]').first();
    const hasImage = await imageContainer.getAttribute('data-has-image');
    expect(hasImage).toBe('true');

    // Vérifie que le <img> Next/Image est bien présent avec src non vide.
    const img = imageContainer.locator('img');
    await expect(img).toHaveAttribute('src', /\S+/);
  });

  test('toutes les cards visibles ont soit image soit fallback thématique', async ({ page }) => {
    await page.goto('/actualites');
    const cards = page.locator('[data-testid^="actualite-card-"]');
    const count = await cards.count();
    expect(count).toBeGreaterThan(0);

    for (let i = 0; i < count; i++) {
      const card = cards.nth(i);
      // Soit l'image est présente, soit le fallback testid l'est.
      const hasImage = await card.locator('[data-has-image="true"]').count();
      const hasFallback = await card.locator('[data-testid^="actualite-card-fallback-"]').count();
      expect(hasImage + hasFallback).toBeGreaterThan(0);
    }
  });

  test('le fallback affiche une icône thématique + label catégorie (a11y)', async ({ page }) => {
    await page.goto('/actualites');
    const fallbacks = page.locator('[data-testid^="actualite-card-fallback-"]');
    const count = await fallbacks.count();
    if (count === 0) {
      // Tous les articles ont des images seedées — cas nominal post-fix.
      test.skip();
    }
    const first = fallbacks.first();
    // SVG lucide-react = présence d'un <svg> visible dans le fallback.
    await expect(first.locator('svg').first()).toBeVisible();
  });
});
