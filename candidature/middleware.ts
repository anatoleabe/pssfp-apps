import { NextRequest, NextResponse } from 'next/server';

const TOKEN_COOKIE = 'pssfp_candidat_token';
const EXPIRES_COOKIE = 'pssfp_candidat_expires';
const PUBLIC_AUTH_PATHS = ['/login', '/inscription', '/forgot-pin'];
const PROTECTED_PREFIXES = ['/dossier'];

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

export async function middleware(request: NextRequest): Promise<NextResponse> {
  const pathname = request.nextUrl.pathname;
  const protectedRoute = PROTECTED_PREFIXES.some((prefix) => pathname.startsWith(prefix));
  const publicAuthRoute = PUBLIC_AUTH_PATHS.some(
    (path) => pathname === path || pathname.startsWith(`${path}/`),
  );
  const token = request.cookies.get(TOKEN_COOKIE)?.value;

  if (!token) {
    if (protectedRoute) {
      return NextResponse.redirect(new URL('/login?reason=session_expired', request.url));
    }
    return NextResponse.next();
  }

  const validity = await sessionIsValid(token);
  if (validity === 'invalid') {
    const response = protectedRoute
      ? NextResponse.redirect(new URL('/login?reason=session_expired', request.url))
      : NextResponse.next();
    expireSessionCookies(response);
    return response;
  }

  if (validity === 'valid' && publicAuthRoute) {
    return NextResponse.redirect(new URL('/dossier', request.url));
  }

  if (validity === 'unknown' && protectedRoute) {
    // Ne pas supprimer un token potentiellement valide lors d'une panne API,
    // mais ne jamais laisser la route protégée lever une exception brute.
    return NextResponse.redirect(new URL('/login?reason=service_unavailable', request.url));
  }

  const requestHeaders = new Headers(request.headers);
  requestHeaders.set('x-candidat-session-valid', validity === 'valid' ? '1' : '0');
  return NextResponse.next({ request: { headers: requestHeaders } });
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico|logos/).*)'],
};
