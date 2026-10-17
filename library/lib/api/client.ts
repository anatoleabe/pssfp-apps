import type { ApiResult } from './types';

export const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

interface GetOptions { revalidate?: number; signal?: AbortSignal; timeoutMs?: number }

/**
 * GET JSON sur l'API ; renvoie toujours un ApiResult (status 0 = réseau ou délai).
 * Seule exception : l'annulation par le signal de l'appelant est relancée.
 * Délai et signal de l'appelant sont combinés à la main (pas d'AbortSignal.any,
 * absent de certains runtimes, dont edge).
 */
export async function apiGet<T>(path: string, options: GetOptions = {}): Promise<ApiResult<T>> {
  const callerSignal = options.signal;
  const controller = new AbortController();
  const abort = (): void => controller.abort();
  let timer: ReturnType<typeof setTimeout> | undefined;
  try {
    const url = `${API_BASE_URL}${path.startsWith('/') ? path : `/${path}`}`;
    timer = setTimeout(abort, options.timeoutMs ?? 5000);
    if (callerSignal?.aborted) abort();
    else callerSignal?.addEventListener('abort', abort, { once: true });

    const res = await fetch(url, {
      headers: { Accept: 'application/json', 'Accept-Language': 'fr' },
      next: options.revalidate !== undefined ? { revalidate: options.revalidate } : undefined,
      signal: controller.signal,
    });
    if (!res.ok) return { ok: false, error: { message: `API ${res.status}`, status: res.status } };
    return { ok: true, data: (await res.json()) as T };
  } catch (e) {
    if (callerSignal?.aborted) throw e;
    return { ok: false, error: { message: e instanceof Error ? e.message : 'network', status: 0 } };
  } finally {
    if (timer !== undefined) clearTimeout(timer);
    callerSignal?.removeEventListener('abort', abort);
  }
}
