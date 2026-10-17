import type { ApiResult } from './types';

export const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

interface GetOptions { revalidate?: number; signal?: AbortSignal; timeoutMs?: number }

/** GET JSON sur l'API ; ne lève jamais : renvoie un ApiResult (status 0 = réseau). */
export async function apiGet<T>(path: string, options: GetOptions = {}): Promise<ApiResult<T>> {
  const url = `${API_BASE_URL}${path.startsWith('/') ? path : `/${path}`}`;
  const timeout = AbortSignal.timeout(options.timeoutMs ?? 5000);
  const signal = options.signal ? AbortSignal.any([options.signal, timeout]) : timeout;
  try {
    const res = await fetch(url, {
      headers: { Accept: 'application/json', 'Accept-Language': 'fr' },
      next: options.revalidate !== undefined ? { revalidate: options.revalidate } : undefined,
      signal,
    });
    if (!res.ok) return { ok: false, error: { message: `API ${res.status}`, status: res.status } };
    return { ok: true, data: (await res.json()) as T };
  } catch (e) {
    if (options.signal?.aborted) throw e;
    return { ok: false, error: { message: e instanceof Error ? e.message : 'network', status: 0 } };
  }
}
