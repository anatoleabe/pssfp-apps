import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { expect, test } from '@playwright/test';

/**
 * Régression « bouton Certifier inatteignable sur téléphone ».
 *
 * Les modales du portail sont des overlays `fixed inset-0` centrés en flex.
 * Sans conteneur scrollable, dès que le contenu dépasse la hauteur du viewport
 * le centrage vertical rejette les boutons d'action hors écran — et comme rien
 * ne défile, ils deviennent définitivement inatteignables. C'est ce qui bloquait
 * les candidats en 360x640 devant « Certifier et soumettre ma candidature ».
 *
 * Le test lit les `className` réels des composants (aucune duplication de
 * classes dans le test : si quelqu'un retire `overflow-y-auto`, le test échoue)
 * puis les monte sur une page servie par l'app, donc avec le CSS Tailwind
 * réellement compilé, et vérifie que le bouton d'action reste cliquable.
 */

const COMPONENTS = join(__dirname, '..', '..', 'components');

/** Extrait les deux premiers `className="…"` du composant : overlay puis boîte. */
function overlayClasses(relativePath: string): { backdrop: string; dialog: string } {
  const source = readFileSync(join(COMPONENTS, relativePath), 'utf-8');
  const overlayIndex = source.indexOf('fixed inset-0');
  expect(overlayIndex, `overlay introuvable dans ${relativePath}`).toBeGreaterThan(-1);

  const classNames = [...source.slice(overlayIndex - 200).matchAll(/className="([^"]+)"/g)].map(
    (match) => match[1],
  );
  const backdrop = classNames.find((value) => value.includes('fixed inset-0'));
  const dialog = classNames[classNames.indexOf(backdrop as string) + 1];
  expect(backdrop, relativePath).toBeTruthy();
  expect(dialog, relativePath).toBeTruthy();

  return { backdrop: backdrop as string, dialog: dialog as string };
}

/** Contenu volontairement long : c'est le cas qui déborde en 360x640. */
const LONG_CONTENT = `
  <h3 class="font-heading text-2xl font-bold">Confirmer la soumission</h3>
  <p class="mt-3 text-sm leading-relaxed">Cette action est definitive. Une fois votre candidature
    soumise, vous ne pourrez plus la modifier ni revenir sur les informations certifiees.</p>
  <section class="mt-5 rounded-lg border p-4">
    <h4 class="font-heading font-bold">Informations essentielles a relire</h4>
    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
      ${[
        ['Candidat', 'YVAN CEDRIC LE KOUD'],
        ['Adresse e-mail', 'candidat@example.test'],
        ['Telephone', '+237 600000000'],
        ['Specialite', 'Gouvernance Territoriale et Finances Publiques locales'],
        ['Diplome', 'Licence'],
        ['Etablissement', 'Institut Superieur de Technologie Appliquee et de Gestion'],
        ['Situation actuelle', 'Fonctionnaire'],
        ['Employeur', 'Ministere des Finances'],
      ]
        .map(([label, value]) => `<div><dt class="text-xs uppercase">${label}</dt><dd class="mt-0.5 break-words font-medium">${value}</dd></div>`)
        .join('')}
    </dl>
  </section>
  <div class="mt-5 flex items-start gap-3 text-sm font-semibold leading-relaxed">
    <input type="checkbox" class="mt-0.5 h-5 w-5 shrink-0" />
    <span>J'ai relu les informations de mon dossier et je confirme qu'elles sont exactes.</span>
  </div>
  <div class="mt-6 flex flex-wrap justify-end gap-3">
    <button type="button" class="rounded-md border px-4 py-2 text-sm font-semibold">Annuler</button>
    <button type="button" id="modal-primary-action" class="rounded-md bg-[#4A2E67] px-4 py-2 text-sm font-semibold text-white">
      Certifier et soumettre ma candidature
    </button>
  </div>
`;

const MOBILE_VIEWPORTS = [
  { width: 320, height: 568, label: '320x568' },
  { width: 360, height: 640, label: '360x640' },
  { width: 375, height: 667, label: '375x667' },
];

const OVERLAYS = [
  'dossier/DossierCompleteness.tsx',
  'WithdrawDialog/index.tsx',
  'wizard/WizardContainer.tsx',
];

for (const component of OVERLAYS) {
  for (const viewport of MOBILE_VIEWPORTS) {
    test(`${component} — bouton d'action atteignable en ${viewport.label}`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      // Page réelle de l'app : la feuille Tailwind compilée y est déjà chargée.
      await page.goto('/login');

      const { backdrop, dialog } = overlayClasses(component);
      await page.evaluate(
        ([backdropClass, dialogClass, html]) => {
          const overlay = document.createElement('div');
          overlay.className = backdropClass;
          const box = document.createElement('div');
          box.className = dialogClass;
          box.innerHTML = html;
          overlay.appendChild(box);
          document.body.appendChild(overlay);
        },
        [backdrop, dialog, LONG_CONTENT] as const,
      );

      // `click` fait défiler l'élément dans le viewport puis clique : il échoue
      // si le conteneur n'est pas scrollable, ce qui est exactement le bug.
      await page.locator('#modal-primary-action').click({ timeout: 5_000 });
    });
  }
}
