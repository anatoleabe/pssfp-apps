import createIntlMiddleware from 'next-intl/middleware';
import { NextRequest, NextResponse } from 'next/server';

import { DEFAULT_LOCALE, SUPPORTED_LOCALES, isSupportedLocale } from './i18n';
import { localePrefix } from './navigation';

const TOKEN_COOKIE = 'pssfp_candidat_token';
const EXPIRES_COOKIE = 'pssfp_candidat_expires';
const PROTECTED_PREFIXES = ['/dossier'];

/**
 * Détection de langue : en-tête `Accept-Language` à la première visite, puis
 * cookie `NEXT_LOCALE` dès que le visiteur a utilisé le sélecteur. Le cookie
 * prime toujours sur l'en-tête. Les deux versions restent joignables par URL
 * directe, sans redirection imposée aux robots d'indexation.
 */
const intlMiddleware = createIntlMiddleware({
  locales: SUPPORTED_LOCALES,
  defaultLocale: DEFAULT_LOCALE,
  localePrefix,
  localeDetection: true,
});

/** Sépare le préfixe de locale du chemin applicatif (`/en/dossier` -> `en`, `/dossier`). */
function splitLocale(pathname: string): { locale: string; pathWithoutLocale: string } {
  const [, maybeLocale, ...rest] = pathname.split('/');

  if (isSupportedLocale(maybeLocale)) {
    return { locale: maybeLocale, pathWithoutLocale: `/${rest.join('/')}` };
  }

  return { locale: DEFAULT_LOCALE, pathWithoutLocale: pathname };
}

/** Reconstruit une URL applicative en conservant la locale courante. */
function localizedUrl(path: string, locale: string, request: NextRequest): URL {
  const prefix = locale === DEFAULT_LOCALE ? '' : `/${locale}`;

  return new URL(`${prefix}${path}`, request.url);
}

function expireSessionCookies(response: NextResponse): void {
  const secure = process.env.NODE_ENV === 'production';
  const shared = {
    secure,
    sameSite: 'lax' as const,
    path: '/',
    expires: new Date(0),
    maxAge: 0,
    ...(secure ? { domain: '.pssfp.org' } : {}),
  };
  response.cookies.set(TOKEN_COOKIE, '', { ...shared, httpOnly: true });
  response.cookies.set(EXPIRES_COOKIE, '', { ...shared, httpOnly: false });
}

async function sessionIsValid(token: string): Promise<'valid' | 'invalid' | 'unknown'> {
  // Les jetons personnels Sanctum ont la forme `{id}|{plainTextToken}`.
  // Rejette localement les cookies manifestement forgés sans solliciter l'API.
  if (!/^\d+\|[A-Za-z0-9]{20,}$/.test(token)) return 'invalid';
  const apiBase = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';
  try {
    const response = await fetch(`${apiBase}/applications/me`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      cache: 'no-store',
    });
    // 404 signifie « campagne/dossier absent », mais l'auth Sanctum a bien
    // accepté le token. Seul 401 prouve une session invalide.
    return response.status === 401 ? 'invalid' : 'valid';
  } catch {
    return 'unknown';
  }
}

/**
 * Délègue à next-intl en lui passant une requête déjà porteuse de l'en-tête de
 * session.
 *
 * next-intl recopie les en-têtes de la requête qu'on lui donne dans sa propre
 * réécriture, et y ajoute son `x-next-intl-locale`. Enrichir la requête en
 * amont laisse donc passer les deux. Rejouer soi-même la réécriture à partir de
 * `x-middleware-rewrite` perdrait au contraire l'en-tête interne de next-intl,
 * et toutes les pages tomberaient en 404 faute de locale résolue.
 */
function withSessionHeader(request: NextRequest, sessionValid: boolean): NextResponse {
  const requestHeaders = new Headers(request.headers);
  requestHeaders.set('x-candidat-session-valid', sessionValid ? '1' : '0');

  // Le corps n'est pas recopié : seule la décision de réécriture de next-intl
  // nous intéresse, Next l'applique ensuite à la requête réelle.
  const patchedRequest = new NextRequest(request.nextUrl, {
    method: request.method,
    headers: requestHeaders,
  });

  return intlMiddleware(patchedRequest);
}

export async function middleware(request: NextRequest): Promise<NextResponse> {
  const { locale, pathWithoutLocale } = splitLocale(request.nextUrl.pathname);

  const protectedRoute = PROTECTED_PREFIXES.some((prefix) => pathWithoutLocale.startsWith(prefix));
  const token = request.cookies.get(TOKEN_COOKIE)?.value;

  if (!token) {
    if (protectedRoute) {
      return NextResponse.redirect(localizedUrl('/login?reason=session_expired', locale, request));
    }
    return withSessionHeader(request, false);
  }

  const validity = await sessionIsValid(token);
  if (validity === 'invalid') {
    const response = protectedRoute
      ? NextResponse.redirect(localizedUrl('/login?reason=session_expired', locale, request))
      : withSessionHeader(request, false);
    expireSessionCookies(response);
    return response;
  }

  // Session valide sur /login, /inscription ou /forgot-pin : on laisse la page
  // s'afficher plutôt que de rediriger en silence vers /dossier (audit A-36).
  // Elle lit `x-candidat-session-valid` et propose explicitement de continuer
  // sur le dossier ouvert ou de se déconnecter pour en créer un autre.

  if (validity === 'unknown' && protectedRoute) {
    // Ne pas supprimer un token potentiellement valide lors d'une panne API,
    // mais ne jamais laisser la route protégée lever une exception brute.
    return NextResponse.redirect(localizedUrl('/login?reason=service_unavailable', locale, request));
  }

  return withSessionHeader(request, validity === 'valid');
}

export const config = {
  matcher: ['/((?!api|_next/static|_next/image|favicon.ico|logos/).*)'],
};
