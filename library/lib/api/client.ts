const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

export interface ApiHealth {
  status: 'ok' | 'degraded' | 'down';
  timestamp: string;
}

export type ApiResult<T> =
  | { ok: true; data: T }
  | { ok: false; error: { message: string; status: number } };

export async function getApiHealth(): Promise<ApiResult<ApiHealth>> {
  try {
    const response = await fetch(`${API_BASE_URL}/health`, {
      headers: { Accept: 'application/json', 'Accept-Language': 'fr' },
      next: { revalidate: 30 },
    });
    if (!response.ok) {
      return {
        ok: false,
        error: { message: `API ${response.status}`, status: response.status },
      };
    }
    const data = (await response.json()) as ApiHealth;
    return { ok: true, data };
  } catch (error) {
    return {
      ok: false,
      error: {
        message: error instanceof Error ? error.message : 'Network error',
        status: 0,
      },
    };
  }
}
