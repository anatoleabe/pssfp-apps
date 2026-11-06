import { NextResponse } from 'next/server';

import type { ErreurLettreAcces } from '@/components/dossier/DossierAccesCard';
import { getCandidatToken } from '@/lib/auth/session';
import { redirectTo } from '@/lib/http/redirect';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

/** Motif affiché sur la page des accès quand la lettre ne peut pas être servie. */
function motif(status: number): ErreurLettreAcces {
  if (status === 409) return 'non_prete';
  // Accès refermé entre l'affichage et le clic (versement annulé…) : réessayer n'y changerait rien.
  if (status === 403 || status === 404) return 'indisponible';
  if (status === 429) return 'limite';
  return 'echec';
}

/**
 * GET /dossier/lettre-acces — lettre d'accès eCampus (PDF) de l'auditeur connecté.
 *
 * Elle contient le mot de passe : le jeton httpOnly part à l'API, qui revérifie
 * l'inscription soldée, et le PDF repart en flux, sans cache.
 */
export async function GET(): Promise<NextResponse> {
  const token = await getCandidatToken();
  if (!token) {
    return redirectTo('/login?reason=session_expired');
  }

  let apiResponse: Response;
  try {
    apiResponse = await fetch(`${API_BASE_URL}/applications/me/acces/lettre`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/pdf' },
      cache: 'no-store',
    });
  } catch {
    return redirectTo('/dossier/acces?lettre_acces_error=echec');
  }

  if (apiResponse.status === 401) {
    return redirectTo('/login?reason=session_expired');
  }
  if (!apiResponse.ok || apiResponse.body === null) {
    return redirectTo(`/dossier/acces?lettre_acces_error=${motif(apiResponse.status)}`);
  }

  return new NextResponse(apiResponse.body, {
    status: 200,
    headers: {
      'Content-Type': 'application/pdf',
      'Content-Disposition': apiResponse.headers.get('Content-Disposition') ?? 'attachment; filename="acces-ecampus.pdf"',
      'Cache-Control': 'no-store, private',
    },
  });
}
