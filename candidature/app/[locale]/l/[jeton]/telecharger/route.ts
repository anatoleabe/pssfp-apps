import { NextResponse } from 'next/server';

import { DEFAULT_LOCALE, isSupportedLocale } from '@/i18n';
import { visiteurHeaders } from '@/lib/api/visiteur';
import { redirectTo } from '@/lib/http/redirect';
import { JETON_LIEN_LETTRE, motifDepuisReponse, type MotifErreurLienLettre } from '@/lib/lien-lettre';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';
const DATE = /^\d{4}-\d{2}-\d{2}$/;
const DELAI_API_MS = 20_000;

/**
 * POST /l/{jeton}/telecharger — date de naissance du formulaire du lien SMS.
 *
 * L'API compare la date et compte l'essai ; la lettre (elle contient le mot
 * de passe) repart en flux, sans cache. Toute autre issue revient sur la page
 * du lien avec un motif (303 : le navigateur y retourne en GET).
 */
export async function POST(request: Request, { params }: { params: { locale: string; jeton: string } }): Promise<NextResponse> {
  // Redirection construite sur des valeurs validées seulement : locale connue, jeton au bon format.
  const prefixe = isSupportedLocale(params.locale) && params.locale !== DEFAULT_LOCALE ? `/${params.locale}` : '';
  if (!JETON_LIEN_LETTRE.test(params.jeton)) {
    return redirectTo(`${prefixe}/login?mode=matricule`, 303);
  }
  const page = `${prefixe}/l/${params.jeton}`;
  const retour = (motif: MotifErreurLienLettre, restantes?: number): NextResponse =>
    redirectTo(`${page}?erreur=${motif}${restantes === undefined ? '' : `&restantes=${restantes}`}`, 303);

  let date = '';
  try {
    date = String((await request.formData()).get('date_naissance') ?? '');
  } catch {
    return retour('format');
  }
  if (!DATE.test(date)) {
    return retour('format');
  }
  // Recette E2E sans API : même issue qu'une API injoignable.
  if (process.env.PSSFP_E2E_OFFLINE === '1') {
    return retour('indisponible');
  }

  let api: Response;
  try {
    api = await fetch(`${API_BASE_URL}/lettre-acces/${params.jeton}`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(await visiteurHeaders()) },
      body: JSON.stringify({ date_naissance: date }),
      cache: 'no-store',
      signal: AbortSignal.timeout(DELAI_API_MS),
    });
  } catch {
    return retour('indisponible');
  }

  if (api.ok && api.body !== null && (api.headers.get('Content-Type') ?? '').includes('application/pdf')) {
    return new NextResponse(api.body, {
      status: 200,
      headers: {
        'Content-Type': 'application/pdf',
        'Content-Disposition': api.headers.get('Content-Disposition') ?? 'attachment; filename="acces-foad.pdf"',
        'Cache-Control': 'no-store, private',
        'X-Robots-Tag': 'noindex, nofollow',
      },
    });
  }

  const corps = (await api.json().catch(() => ({}))) as { code?: string; restantes?: number };
  const motif = motifDepuisReponse(api.status, corps.code);

  return retour(motif, motif === 'date' ? Math.max(0, Number(corps.restantes ?? 0)) : undefined);
}
