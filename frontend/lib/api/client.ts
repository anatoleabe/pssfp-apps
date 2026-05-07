import type { ApiHealth, ApiResult } from './types';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

interface FetchOptions {
  revalidate?: number;
  signal?: AbortSignal;
}

async function apiGet<T>(
  path: string,
  options: FetchOptions = {},
): Promise<ApiResult<T>> {
  const url = `${API_BASE_URL}${path.startsWith('/') ? path : `/${path}`}`;

  try {
    const response = await fetch(url, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        'Accept-Language': 'fr',
      },
      next: options.revalidate !== undefined ? { revalidate: options.revalidate } : undefined,
      signal: options.signal,
    });

    if (!response.ok) {
      return {
        ok: false,
        error: {
          message: `API ${response.status} ${response.statusText}`,
          status: response.status,
        },
      };
    }

    const data = (await response.json()) as T;
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

export async function getApiHealth(): Promise<ApiResult<ApiHealth>> {
  return apiGet<ApiHealth>('/health', { revalidate: 30 });
}
