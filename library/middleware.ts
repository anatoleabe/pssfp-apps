import { NextResponse, type NextRequest } from 'next/server';
import { resolveLegacyPath } from './lib/api/library';
import { legacyLookupPath, safeRedirectTarget } from './lib/redirects';

/**
 * Anciennes URL (Joomla : `/index.php/…`, `/index.php?option=…`, fiches DOCman, `…/file`, pages de
 * catégorie) : tout chemin que l'app ne sert pas est soumis à `GET /v1/library/redirects` (avec sa
 * query string pour `/index.php?…`). Trouvé → 301 (ou 302) vers un chemin interne du même site
 * (`safeRedirectTarget`) ; sinon, ou si l'API échoue, suite normale (→ 404 de l'app).
 * Le middleware ne casse jamais une page : toute erreur mène à `NextResponse.next()`.
 */

/** Préfixes servis par l'app (routes et ressources statiques) : jamais résolus comme anciennes URL. */
const APP_PREFIXES = [
  '/collections',
  '/recherche',
  '/documents',
  '/a-propos',
  '/dev',
  '/_next',
  '/__nextjs',
  '/logos',
  '/icon.png',
  '/apple-icon.png',
  '/favicon.ico',
  '/sitemap.xml',
  '/robots.txt',
];

/** Réponses mémorisées (par processus) : un robot qui balaie des URL n'épuise pas l'API. */
const CACHE_TTL_MS = 10 * 60 * 1000;
const CACHE_MAX_ENTRIES = 1000;
type Resolution = { to: string; status: 301 | 302 } | null;
const cache = new Map<string, { value: Resolution; expires: number }>();

function isAppPath(pathname: string): boolean {
  return pathname === '/' || APP_PREFIXES.some((p) => pathname === p || pathname.startsWith(`${p}/`));
}

function remember(path: string, value: Resolution): void {
  if (cache.size >= CACHE_MAX_ENTRIES) {
    const oldest = cache.keys().next();
    if (!oldest.done) cache.delete(oldest.value);
  }
  cache.set(path, { value, expires: Date.now() + CACHE_TTL_MS });
}

/** undefined : API indisponible (rien n'est mémorisé, on réessaiera) ; null : aucune redirection. */
async function resolve(path: string): Promise<Resolution | undefined> {
  const hit = cache.get(path);
  if (hit && hit.expires > Date.now()) return hit.value;

  const r = await resolveLegacyPath(path);
  if (!r.ok) {
    if (r.error.status !== 404) return undefined;
    remember(path, null);
    return null;
  }
  const { to_path: to, status_code: code } = r.data.data;
  // La cible est revalidée à chaque redirection (safeRedirectTarget) ; ici, seulement sa forme.
  const value: Resolution = typeof to === 'string' ? { to, status: code === 302 ? 302 : 301 } : null;
  remember(path, value);
  return value;
}

export async function middleware(req: NextRequest): Promise<NextResponse> {
  const { pathname } = req.nextUrl;
  if ((req.method !== 'GET' && req.method !== 'HEAD') || isAppPath(pathname)) return NextResponse.next();

  try {
    const target = await resolve(legacyLookupPath(req.nextUrl));
    const dest = target ? safeRedirectTarget(target.to, req.nextUrl.href) : null;
    if (target && dest) return NextResponse.redirect(dest, target.status);
  } catch {
    // API ou URL cible inattendue : la page suit son cours normal.
  }
  return NextResponse.next();
}

/**
 * Exclut les ressources de Next et les fichiers statiques usuels (images, polices, CSS/JS…).
 * Les autres chemins à extension restent soumis au middleware : les anciennes URL Joomla en
 * portent (`/index.php/…`, `….html`, `….pdf`).
 */
export const config = {
  matcher: ['/((?!_next/static|_next/image|.*\\.(?:png|jpe?g|gif|svg|webp|avif|ico|css|js|map|txt|xml|woff2?|ttf|otf|webmanifest)$).*)'],
};
