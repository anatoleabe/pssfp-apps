const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

export interface ContactPayload {
  nom: string;
  email: string;
  phone?: string;
  organisation?: string;
  subject?: string;
  message: string;
  cgu: boolean;
  cf_turnstile_response?: string;
}

export interface ContactResult {
  ok: boolean;
  status: number;
  message: string;
  errors?: Record<string, string[]>;
}

export async function sendContactMessage(payload: ContactPayload): Promise<ContactResult> {
  try {
    const response = await fetch(`${API_BASE_URL}/contact`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'Accept-Language': 'fr',
      },
      body: JSON.stringify(payload),
    });

    let body: { message?: string; errors?: Record<string, string[]> } = {};
    try {
      body = (await response.json()) as { message?: string; errors?: Record<string, string[]> };
    } catch {
      body = {};
    }

    return {
      ok: response.ok,
      status: response.status,
      message: body.message ?? (response.ok ? 'Message envoyé.' : `API ${response.status}`),
      errors: body.errors,
    };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: error instanceof Error ? error.message : 'Erreur réseau',
    };
  }
}
