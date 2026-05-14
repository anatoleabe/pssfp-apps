# Sprint S5.2 — Fix régressions Stats + Actualités liste

> **Origine** : Re-audit visuel post-S5.1 a confirmé 2 régressions parmi les 7 bugs supposés fixés.
> **Date** : 2026-05-14
> **Effort estimé** : ½ jour
> **PR cible** : `fix/s5.2-stats-actualites`

---

## 1. Bug #45 — Stats NumberTicker bloqué à 0

### Diagnostic Cowork

J'ai lu le code de `frontend/components/magic-ui/number-ticker.tsx` (commit S5.1). Le SSR rend bien `finalText` (la valeur finale formatée) à la ligne 112-113. **Donc au 1er paint visible, l'utilisateur devrait voir 13/5/1200+/10+.**

Mais en pratique l'audit a observé **`13 / 0 / 28+ / 0+`** (mix de vraies valeurs et de valeurs animées intermédiaires bloquées). Donc quelque chose écrase le textContent SSR après le mount.

**Cause racine probable** : le 1er useEffect (lignes 56-90) :

```typescript
useEffect(() => {
  if (!mounted) return;
  if (reduceMotion) { /* ... */ return; }
  if (!isInView) return;          // ← early return si pas visible

  motionValue.set(0);              // ← RESET À 0
  ref.current.textContent = formatNumber(0, decimalPlaces);  // ← écrase "13" en "0"

  const startTimeout = setTimeout(() => {
    motionValue.set(value);        // ← anim démarre
  }, delay * 1000);

  const watchdog = setTimeout(() => {
    ref.current.textContent = formatNumber(value, decimalPlaces);
    motionValue.set(value);
  }, delay * 1000 + 1800);

  return () => {
    clearTimeout(startTimeout);
    clearTimeout(watchdog);
  };
}, [mounted, reduceMotion, motionValue, isInView, delay, value, direction, decimalPlaces]);
```

**Problèmes identifiés** :

1. **Le reset à 0 (ligne 69-72) écrase le textContent SSR dès que isInView passe true.** Le visiteur voit donc "13" pendant 1 frame puis "0" pendant 0 à 1.8s, puis animation.

2. **Le watchdog (ligne 79-84) est conditionné à `isInView === true`.** Si la card est wrappée dans `BlurFade` (ce qu'on voit dans `HomeStats` ligne 117) qui démarre à opacity 0, le `useInView` peut ne pas détecter la stat comme visible suffisamment longtemps. Si le useEffect a déjà fait son early return ligne 66, il ne s'exécute plus tant que `isInView` ne re-change pas. Le watchdog n'est jamais armé.

3. **Avec `useInView({ once: true })` ligne 48**, isInView ne re-fire qu'une seule fois. Si à ce moment-là il y a un état transitoire (page invisible, idle), le set 0 fire mais le set value ne fire jamais → bloqué à 0.

### Fix à appliquer

Réécrire le 1er useEffect pour garantir le watchdog dans **tous les cas** (pas seulement quand `isInView` est true) :

```typescript
useEffect(() => {
  if (!mounted) return;

  if (reduceMotion) {
    if (ref.current) {
      ref.current.textContent = formatNumber(value, decimalPlaces);
    }
    return;
  }

  // ⚠️ Watchdog ABSOLU : kick après 2.5s peu importe l'état.
  // Garantit que la valeur finale est affichée même si l'anim ne démarre jamais.
  const watchdog = setTimeout(() => {
    if (ref.current) {
      ref.current.textContent = formatNumber(value, decimalPlaces);
    }
    motionValue.set(value);
  }, 2500);

  if (!isInView) {
    // Pas dans le viewport : on garde la valeur finale SSR.
    // Le watchdog reste armé au cas où isInView ne fire jamais.
    return () => clearTimeout(watchdog);
  }

  // Dans le viewport : animation from 0 to value.
  motionValue.set(direction === 'down' ? value : 0);
  if (ref.current) {
    ref.current.textContent = formatNumber(
      direction === 'down' ? value : 0,
      decimalPlaces,
    );
  }
  const startTimeout = setTimeout(() => {
    motionValue.set(direction === 'down' ? 0 : value);
  }, delay * 1000);

  return () => {
    clearTimeout(startTimeout);
    clearTimeout(watchdog);
  };
}, [mounted, reduceMotion, motionValue, isInView, delay, value, direction, decimalPlaces]);
```

**Changement clé** : le watchdog est désormais armé **avant** le check `isInView`. Donc même si la card reste invisible pour une raison X, le watchdog kick après 2.5s et écrit la valeur finale.

### Tests à ajouter

- Test Playwright qui assert qu'après load `localhost:6001/` + wait 3s, les 4 stats affichent bien `13`, `5`, `1 200+`, `10+` (et pas `0`).
- Test Playwright qui assert le même résultat avec `prefers-reduced-motion: reduce`.
- Test Playwright avec une stat hors viewport au load (genre footer stat) : vérifier que la valeur finale est aussi affichée.

---

## 2. Bug #46 — Photos /actualites cards = placeholders gradient

### Diagnostic Cowork

J'ai comparé les deux composants :

**`HomeActualites` (fonctionne)** — `frontend/components/HomeActualites/index.tsx` :
```typescript
const articlesResult = await listArticles({ featured: true });
const realArticles = articlesResult.ok ? articlesResult.data.data.slice(0, 3) : [];
// ...
{article.featured_image_path && (
  <Image src={mediaUrl(article.featured_image_path)} ... />
)}
```

**`/actualites` page liste (KO)** — `frontend/app/(public)/actualites/page.tsx` :
```typescript
const result = await listArticles({ page: pageNum });
// ...
{article.featured_image_path && (
  <Image src={mediaUrl(article.featured_image_path)} ... />
)}
```

**Le code Image est strictement identique.** Le rendering aussi (div parent avec gradient en background, `<Image>` en absolute fill par-dessus).

Donc les 4 hypothèses possibles :

1. **`listArticles({ page })` retourne un payload sans `featured_image_path`** alors que `listArticles({ featured: true })` le retourne. Le backend filtre les champs selon le contexte.

2. **L'URL signée MinIO expire entre les 2 fetches** — peu probable car les 2 sont SSR back-to-back.

3. **Next.js `revalidate = 300` côté `/actualites` cache un payload obsolète** alors que la home a un cache différent. Le cache contient les vieux articles sans `featured_image_path`.

4. **Le fichier MinIO n'a pas la même URL résolue dans les 2 contextes** (next.config.js domain whitelist ou autre).

### Fix à appliquer

**Étape 1 — Diagnostic en console** : ajouter un log temporaire dans `/actualites/page.tsx` après le fetch :

```typescript
const result = await listArticles({ page: pageNum });
console.log('[/actualites] result.data.data[0]:', JSON.stringify(result.data?.data?.[0], null, 2));
```

Puis recharger localhost:6001/actualites et lire la console serveur Next.js. On verra si `featured_image_path` est null ou rempli.

**Étape 2 selon le résultat** :

- **Si `featured_image_path` est null côté liste mais rempli côté home featured** :
  - Le bug est côté backend Laravel. Vérifier le `ArticleResource` (Laravel API Resource) qui sérialise les articles : peut-être que le champ `featured_image_path` n'est exposé que sur les featured. Aller dans `backend/app/Http/Resources/ArticleResource.php` et s'assurer que `featured_image_path` est TOUJOURS retourné (pas conditionné).
  - OU dans le `ArticleController@index`, vérifier que le `select` ne fait pas du cherry-picking selon le filter.

- **Si `featured_image_path` est rempli mais l'Image ne charge pas** :
  - Inspecter le DOM en devtools → trouver `<img>` dans une card placeholder → regarder l'attribut `src` et le `data-nimg`.
  - Si l'URL est différente entre home et /actualites pour le même article → c'est un bug `mediaUrl()` qui dépend du contexte (peut-être base URL différente).
  - Si l'URL est identique mais l'image affiche 404 → c'est un bug next.config.js `remotePatterns` ou `images.domains` qui bloque l'URL en page liste.

- **Si `revalidate=300` est en cause** :
  - Forcer un cache miss : `export const dynamic = 'force-dynamic'` ou `revalidate = 0` temporairement pour tester.
  - Si ça résout, c'est un problème de cache Next obsolète. Le solution propre : `revalidate=60` au lieu de 300, ou tag-based revalidation depuis le backend après publication d'article.

**Étape 3 — fix final** : selon le diagnostic, appliquer le bon correctif. Documenter la cause racine dans le PR description.

### Tests à ajouter

- Test Playwright `/actualites` : vérifier qu'au moins une card a un attribut `<img src="...">` non vide (pas juste le gradient placeholder).
- Snapshot Playwright à mettre à jour.

---

## 3. Inspect-First (rappel)

- Lire les composants existants AVANT de modifier — pas de refonte complète, juste les fixes ciblés.
- Pas de `migrate:fresh`. Pas de seed destructif.

---

## 4. Prompt prêt à coller dans Claude Code

```
Sprint S5.2 — Fix régressions Stats + Actualités liste (½ jour).

Lis docs/sprints/sprint-S5.2-fix-stats-actualites.md de bout en bout.
Le diagnostic Cowork est précis : pas besoin d'investiguer à l'aveugle.

Branche: fix/s5.2-stats-actualites
PR cible: 1 PR consolidé, closes #45 et #46.

═══════════════════════════════════════════════
TÂCHE 1 — NumberTicker (bug #45)
═══════════════════════════════════════════════

Fichier: frontend/components/magic-ui/number-ticker.tsx

Réécris le useEffect des lignes 56-90 selon la spec section 1
"Fix à appliquer". Le changement clé :
  - Armer le watchdog AVANT le early return sur !isInView
  - Ainsi même si isInView ne devient jamais true (BlurFade opacity 0
    qui bloque l'IntersectionObserver), le watchdog kick à 2.5s et
    écrit la valeur finale.

Ne touche pas au 2e useEffect (springValue.on('change')) ni au SSR.

═══════════════════════════════════════════════
TÂCHE 2 — Photos /actualites (bug #46)
═══════════════════════════════════════════════

Fichiers concernés:
- frontend/app/(public)/actualites/page.tsx (composant liste)
- frontend/components/HomeActualites/index.tsx (référence qui marche)
- frontend/lib/api/articles.ts (fetch logique)
- backend/app/Http/Resources/ArticleResource.php (sérialisation)
- backend/app/Http/Controllers/Api/ArticleController.php (endpoint)

Procédure de diagnostic (cf section 2 de la spec):

Étape A — Ajouter un console.log temporaire dans page.tsx après le
listArticles({ page: pageNum }):
  console.log('[ACTUALITES_DEBUG] articles:', JSON.stringify(result.data?.data, null, 2));

Étape B — Recharger localhost:6001/actualites et lire la console
serveur Next.js. Vérifier:
  - featured_image_path est null ou rempli sur chacun des articles?
  - category, category_label, excerpt, published_at sont OK?
  - title, slug sont OK?

Étape C selon résultat:
  CAS 1 — featured_image_path null côté liste:
    Le bug est côté Laravel. Aller dans:
    backend/app/Http/Resources/ArticleResource.php
    S'assurer que featured_image_path est TOUJOURS retourné, pas
    conditionné par un when() ou whenLoaded(). Si problème de select
    columns côté Controller, élargir le select.
    Pendant qu'on y est: vérifier que listArticles({featured:true})
    et listArticles({page}) appellent bien le même endpoint Laravel
    et reçoivent le même schéma de réponse.

  CAS 2 — featured_image_path rempli mais image ne charge pas:
    Inspecter DOM dans devtools → <img> attribut src.
    Si URL différente entre home et /actualites pour même article:
      Bug mediaUrl() — vérifier frontend/lib/media.ts pour contexte
      runtime SSR vs client.
    Si URL identique mais 404:
      Vérifier next.config.js remotePatterns/images.domains.
      Si l'URL MinIO n'est pas whitelistée pour la page liste, l'ajouter.

  CAS 3 — Cache Next obsolète (revalidate=300):
    Tester avec `export const dynamic = 'force-dynamic'` au-dessus du
    composant page.tsx. Si ça résout: c'est un problème de cache.
    Solution propre: passer revalidate à 60 ou ajouter un tag-based
    revalidation au backend.

Étape D — Appliquer le fix identifié, retirer le console.log,
documenter la cause racine dans le PR description.

═══════════════════════════════════════════════
TESTS
═══════════════════════════════════════════════

À ajouter dans frontend/tests/playwright/:

1. home-stats-watchdog.spec.ts:
   - load localhost:6001
   - wait 3s
   - assert each .home-stats stat number is NOT "0" or "0+"
   - assert valeurs exactes: 13, 5, 1 200+ ou similaire, 10+
   - répéter avec emulateMedia { reducedMotion: 'reduce' }

2. actualites-images.spec.ts:
   - load localhost:6001/actualites
   - locator first article card → <img>
   - assert src attribute is not empty
   - assert src matches /storage/ ou /media/ pattern

═══════════════════════════════════════════════
VÉRIFICATION
═══════════════════════════════════════════════

- npx tsc --noEmit (3 apps)
- npx next lint (3 apps)
- npx next build production
- Re-test manuel localhost:6001:
  * Home: stats affichent 13/5/1 200+/10+ après 3s
  * /actualites: vraies photos sur cards Formation continue + P14
  * Toggle dark mode aller-retour: stats stables
  * prefers-reduced-motion: stats stables

PR description format:
  ## Cause racine
  [Explication précise du diagnostic + cas trouvé]
  
  ## Fix
  - NumberTicker: watchdog armé avant early return isInView
  - /actualites: [selon le cas 1/2/3 trouvé]
  
  ## Tests
  - 2 nouveaux .spec.ts Playwright
  - Snapshots mis à jour
  
  Closes #45, closes #46.

Reporte le PR + captures avant/après.
```

---

## 5. Communication post-fix

Une fois le PR #s5.2 mergé, le site est **complètement démo-ready**. On pourra :

1. Lancer un dernier audit visuel rapide pour confirmer (½h).
2. Rédiger le brief démo COPIL (parcours guidé 15 min, Q&A anticipées, communication ADR-0008).
3. Programmer la démo Pr. BASAHAG.

Sources de référence : `docs/audits/sprint-s5-visual-audit.md` + `docs/sprints/sprint-S5.1-identite-evolution.md` + ce document.

---

**Versionné** : `pssfp/docs/sprints/sprint-S5.2-fix-stats-actualites.md` (v1.0)
**Tâches associées** : #45, #46
