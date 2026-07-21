import { NextRequest, NextResponse } from 'next/server';

const COOKIES = [
  { name: 'pssfp_candidat_token', httpOnly: true },
  { name: 'pssfp_candidat_expires', httpOnly: false },
  { name: 'pssfp_candidat_pin_reset', httpOnly: true },
] as const;

/**
 * Expire la session dans un Route Handler, seul contexte HTTP autorisé à
 * modifier les cookies. Les Server Components redirigent ici lorsqu'une
 * révocation intervient entre le contrôle middleware et leur appel API.
 */
export function GET(request: NextRequest): NextResponse {
  const response = NextResponse.redirect(new URL('/login?reason=session_expired', request.url));
  const isProd = process.env.NODE_ENV === 'production';
  for (const cookie of COOKIES) {
    response.cookies.set(cookie.name, '', {
      httpOnly: cookie.httpOnly,
      secure: isProd,
      sameSite: 'lax',
      path: '/',
      ...(isProd ? { domain: '.pssfp.org' } : {}),
      expires: new Date(0),
      maxAge: 0,
    });
  }
  return response;
}
