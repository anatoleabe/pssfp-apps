import { NextResponse, type NextRequest } from 'next/server';

import { getCandidatToken, isConsultation } from '@/lib/auth/session';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';
const ETAPES = ['cadrage', 'depot_projet', 'presoutenance1', 'presoutenance2', 'depot_final'];

/**
 * POST /dossier/memoire/deposer/{etape} — relaie le PDF (10 Mo) à l'API avec le jeton httpOnly.
 * Route Handler plutôt qu'action serveur : une action serveur est limitée à 1 Mo.
 */
export async function POST(request: NextRequest, { params }: { params: { etape: string } }): Promise<NextResponse> {
  const token = await getCandidatToken();
  if (!token) {
    return NextResponse.json({ message: 'Session expirée.' }, { status: 401 });
  }
  if (await isConsultation()) {
    return NextResponse.json({ message: 'Consultation en lecture seule.' }, { status: 403 });
  }
  if (!ETAPES.includes(params.etape)) {
    return NextResponse.json({ message: 'Étape inconnue.' }, { status: 404 });
  }

  let form: FormData;
  try {
    form = await request.formData();
  } catch {
    return NextResponse.json({ message: 'Formulaire illisible.' }, { status: 400 });
  }

  try {
    const api = await fetch(`${API_BASE_URL}/applications/me/memoire/depots/${params.etape}`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Accept-Language': 'fr' },
      body: form,
      cache: 'no-store',
    });
    const payload = (await api.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]>; data?: unknown };
    const message = payload.errors ? Object.values(payload.errors)[0]?.[0] : payload.message;

    return NextResponse.json(api.ok ? { data: payload.data } : { message: message ?? 'Dépôt non enregistré.' }, { status: api.status });
  } catch {
    return NextResponse.json({ message: 'Service momentanément indisponible.' }, { status: 503 });
  }
}
