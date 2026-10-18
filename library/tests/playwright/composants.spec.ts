import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Page de démonstration /dev/composants (hors production) : données fictives,
 * aucun backend requis. Les slugs viennent de app/dev/composants/fixtures.ts.
 */
const DEMO = '/dev/composants';

test.describe('Composants documentaires', () => {
  test.beforeEach(async ({ page }) => {
    // Un éventuel alert() déclenché par un surlignage piégé fait échouer le test.
    page.on('dialog', (dialog) => {
      throw new Error(`Dialogue inattendu : ${dialog.message()}`);
    });
    // La balise de consultation ne doit jamais dépendre du backend.
    await page.route('**/library/documents/*/view', (route) => route.fulfill({ status: 204 }));
  });

  test('une carte par mode d\'accès, avec le bon badge', async ({ page }) => {
    await page.goto(DEMO);
    const libre = page.getByTestId('document-card-demo-memoire-libre');
    const reserve = page.getByTestId('document-card-demo-cours-reserve');
    const payant = page.getByTestId('document-card-demo-ouvrage-payant');

    await expect(libre.getByTestId('access-badge')).toHaveText('Libre');
    await expect(reserve.getByTestId('access-badge')).toHaveText('Réservé');
    await expect(payant.getByTestId('access-badge')).toHaveText('Payant · 2\u202f500\u00a0FCFA');

    // Carte = article dont le lien principal est le titre.
    await expect(libre).toHaveJSProperty('tagName', 'ARTICLE');
    const title = libre.getByRole('link').first();
    await expect(title).toHaveAttribute('href', '/documents/demo-memoire-libre');
    // Auteurs : deux au plus, puis « et al. ».
    await expect(libre).toContainText('et al.');
  });

  test('couverture typographique sans cover_url, image sinon', async ({ page }) => {
    await page.goto(DEMO);
    const typo = page.getByTestId('document-card-demo-memoire-libre');
    await expect(typo.locator('img')).toHaveCount(0);
    const cover = typo.getByTestId('document-cover-typo');
    await expect(cover).toBeVisible();
    await expect(cover).toHaveAttribute('aria-hidden', 'true');

    const withImage = page.getByTestId('document-card-demo-these-couverture');
    await expect(withImage.getByTestId('document-cover-typo')).toHaveCount(0);
    const img = withImage.locator('img');
    await expect(img).toHaveCount(1);
    await expect(img).toHaveAttribute('alt', /Couverture : /);
  });

  test('couverture du type rapport : libellé « Rapport et publication », texte lisible', async ({ page }) => {
    await page.goto(DEMO);
    const cover = page.getByTestId('document-cover-typo').filter({ hasText: 'Rapport et publication' });
    await expect(cover).toHaveCount(1);
    await expect(cover).toBeVisible();
    // Texte blanc sur fond pétrole clair (contraste calculé ≥ 4,5:1 : 8,1:1 au plus clair du dégradé).
    await expect(cover).toHaveCSS('color', 'rgb(255, 255, 255)');
  });

  test('AccessAction : quatre raisons, liens seulement quand l\'accès est ouvert', async ({ page }) => {
    await page.goto(DEMO);
    const actions = page.getByTestId('access-action');
    await expect(actions).toHaveCount(5);

    const publicActions = page.locator('[data-testid="access-action"][data-reason="public"]');
    const download = publicActions.first().getByRole('link', { name: /Télécharger \(PDF/ });
    await expect(download).toHaveAttribute('href', /\/library\/documents\/[^/]+\/download$/);
    await expect(download).toContainText(/\(PDF, (\d+|\d+,\d)\u202f(ko|Mo)\)$/);
    // Format réel du fichier (présentation importée de l'ancienne bibliothèque), sans taille connue.
    await expect(publicActions.nth(1).getByRole('link')).toHaveText('Télécharger (PPTX)');

    const external = page.locator('[data-testid="access-action"][data-reason="external"]').getByRole('link');
    await expect(external).toHaveAccessibleName(/Consulter en ligne.*nouvelle fenêtre/);
    await expect(external).toHaveAttribute('target', '_blank');
    await expect(external).toHaveAttribute('rel', 'noopener noreferrer');

    const reserved = page.locator('[data-testid="access-action"][data-reason="reserved"]');
    await expect(reserved).toContainText('Réservé aux auditeurs');
    await expect(reserved.getByRole('link')).toHaveCount(0);

    const paid = page.locator('[data-testid="access-action"][data-reason="paid"]');
    await expect(paid).toContainText('bientôt disponible à l\'achat');
    await expect(paid).toContainText('Prix : 2\u202f500\u00a0FCFA');
    await expect(paid.getByRole('link')).toHaveCount(0);
  });

  test('le surlignage n\'injecte que des <mark>', async ({ page }) => {
    await page.goto(DEMO);
    const card = page.getByTestId('document-card-demo-surlignage');
    await expect(card).toBeVisible();
    await expect(card.locator('img')).toHaveCount(0);
    await expect(card.locator('mark')).toHaveCount(1);
    await expect(card).toContainText('<img src=x');
  });

  test('copie de la citation annoncée', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto(DEMO);
    const box = page.getByTestId('citation-box').first();
    await expect(box).toContainText('Bibliothèque PSSFP');
    await box.getByRole('button', { name: /Copier/ }).click();
    await expect(box.getByRole('status')).toHaveText('Citation copiée');
    const copied = await page.evaluate(() => navigator.clipboard.readText());
    expect(copied).toContain('Bibliothèque PSSFP');
    expect(copied).toContain('/documents/demo-memoire-libre');
  });

  test('citation : titre finissant par un point, sans « .. »', async ({ page }) => {
    await page.goto(DEMO);
    const box = page.getByTestId('demo-citation-point').getByTestId('citation-box');
    await expect(box).toContainText('Exemple A., Fictif B. (2019). Article fictif dont le titre finit par un point. Article scientifique. Bibliothèque PSSFP.');
    await expect(box).not.toContainText('..');
  });

  test('pagination : liens, ellipses et page courante', async ({ page }) => {
    await page.goto(DEMO);
    const nav = page.getByRole('navigation', { name: 'Pagination' }).first();
    const current = nav.locator('[aria-current="page"]');
    await expect(current).toHaveCount(1);
    await expect(current).toHaveText('5');
    for (const n of ['1', '4', '6', '12']) await expect(nav.getByRole('link', { name: `Page ${n}`, exact: true })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Page 2', exact: true })).toHaveCount(0);
    await expect(nav.getByRole('link', { name: 'Page précédente' })).toHaveAttribute('href', /page=4/);
    await expect(nav.getByRole('link', { name: 'Page suivante' })).toHaveAttribute('href', /page=6/);
    await expect(nav.getByRole('button')).toHaveCount(0);
  });

  test('états vide et erreur', async ({ page }) => {
    await page.goto(DEMO);
    await expect(page.getByTestId('state-empty')).toHaveAttribute('role', 'status');
    await expect(page.getByTestId('state-error')).toHaveAttribute('role', 'alert');
  });

  test('ViewBeacon signale une consultation', async ({ page }) => {
    const beacon = page.waitForRequest((req) => /\/library\/documents\/[^/]+\/view$/.test(req.url()) && req.method() === 'POST');
    await page.goto(DEMO);
    await beacon;
  });

  test('aucun défilement horizontal à 320 px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await page.goto(DEMO);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });

  test('focus clavier : anneau prune (jamais or) sur le lien de carte et contour global prune', async ({ page }) => {
    await page.goto(DEMO);
    // Modalité clavier : le focus programmatique qui suit déclenche :focus-visible.
    await page.keyboard.press('Tab');
    const link = page.getByTestId('document-card-demo-memoire-libre').getByRole('link').first();
    await link.focus();
    await expect(link).toBeFocused();
    const ring = await link.evaluate((el) => getComputedStyle(el, '::after').boxShadow);
    expect(ring).toContain('rgb(74, 46, 103)');
    expect(ring).not.toContain('rgb(212, 175, 106)');
    // Élément sans classe de focus : contour global `*:focus-visible`.
    const outline = await page.evaluate(() => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = 'x';
      document.getElementById('main')?.append(button);
      button.focus();
      const style = getComputedStyle(button);
      return { color: style.outlineColor, style: style.outlineStyle };
    });
    expect(outline).toEqual({ color: 'rgb(74, 46, 103)', style: 'solid' });
  });

  test('pagination : aucun <li> vide en première ou dernière page', async ({ page }) => {
    await page.goto(DEMO);
    const empty = await page.locator('nav[aria-label="Pagination"] li').evaluateAll((items) => items.filter((li) => li.childElementCount === 0).length);
    expect(empty).toBe(0);
    for (const which of ['first', 'last']) {
      const nav = page.getByTestId(`demo-pagination-${which}`).getByRole('navigation', { name: 'Pagination' });
      await expect(nav).toBeVisible();
      expect(await nav.locator('li').evaluateAll((items) => items.filter((li) => li.childElementCount === 0).length), which).toBe(0);
    }
  });

  test('axe : aucune violation grave', async ({ page }) => {
    await page.goto(DEMO);
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });
});
