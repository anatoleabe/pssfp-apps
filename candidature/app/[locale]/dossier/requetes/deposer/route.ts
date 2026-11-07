import { NextResponse, type NextRequest } from 'next/server';

import { getCandidatToken, isConsultation } from '@/lib/auth/session';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

/**
 * POST /dossier/requetes/deposer — relaie le formulaire (pièces comprises) à l'API avec le
 * jeton httpOnly. Route Handler plutôt qu'action serveur : les pièces vont jusqu'à 5 Mo.
 */
export async function POST(request: NextRequest): Promise<NextResponse> {
  const token = await getCandidatToken();
  if (!token) {
    return NextResponse.json({ message: 'Session expirée.' }, { status: 401 });
  }
  if (await isConsultation()) {
    return NextResponse.json({ message: 'Consultation en lecture seule.' }, { status: 403 });
  }

  let form: FormData;
  try {
    form = await request.formData();
  } catch {
    return NextResponse.json({ message: 'Formulaire illisible.' }, { status: 400 });
  }

  try {
    const api = await fetch(`${API_BASE_URL}/applications/me/requetes`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Accept-Language': 'fr' },
      body: form,
      cache: 'no-store',
    });
    const payload = (await api.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]>; data?: unknown };
    const message = payload.errors ? Object.values(payload.errors)[0]?.[0] : payload.message;

    return NextResponse.json(api.ok ? { data: payload.data } : { message: message ?? 'Requête non enregistrée.' }, { status: api.status });
  } catch {
    return NextResponse.json({ message: 'Service momentanément indisponible.' }, { status: 503 });
  }
}
