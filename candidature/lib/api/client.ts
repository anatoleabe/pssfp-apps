import type {
  ApiResult,
  Campagne,
  CandidatureProfile,
  Departement,
  Pays,
  Region,
  RegisterCandidatPayload,
  RegisterCandidatResponse,
  Specialite,
} from './types';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

interface FetchOptions {
  revalidate?: number;
  signal?: AbortSignal;
  token?: string | null;
  headers?: Record<string, string>;
}

async function apiCall<T>(
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  path: string,
  body?: unknown,
  options: FetchOptions = {},
): Promise<ApiResult<T>> {
  const url = `${API_BASE_URL}${path.startsWith('/') ? path : `/${path}`}`;
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': 'fr',
    ...options.headers,
  };
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }
  if (options.token) {
    headers.Authorization = `Bearer ${options.token}`;
  }

  try {
    const response = await fetch(url, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      next:
        options.revalidate !== undefined
          ? { revalidate: options.revalidate }
          : undefined,
      signal: options.signal,
    });

    let payload: unknown = null;
    if (response.status !== 204) {
      try {
        payload = await response.json();
      } catch {
        payload = null;
      }
    }

    if (!response.ok) {
      const errorPayload = (payload ?? {}) as {
        message?: string;
        errors?: Record<string, string[]>;
      };
      return {
        ok: false,
        status: response.status,
        message: errorPayload.message ?? `API ${response.status}`,
        errors: errorPayload.errors,
      };
    }

    return { ok: true, data: (payload as { data?: T })?.data ?? (payload as T) };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: error instanceof Error ? error.message : 'Network error',
    };
  }
}

export function apiGet<T>(path: string, options?: FetchOptions): Promise<ApiResult<T>> {
  return apiCall<T>('GET', path, undefined, options);
}

export function apiPost<T>(
  path: string,
  body: unknown,
  options?: FetchOptions,
): Promise<ApiResult<T>> {
  return apiCall<T>('POST', path, body, options);
}

export function apiPut<T>(
  path: string,
  body: unknown,
  options?: FetchOptions,
): Promise<ApiResult<T>> {
  return apiCall<T>('PUT', path, body, options);
}

// ---------- Endpoints typés ----------

export function getCurrentCampaign(): Promise<ApiResult<Campagne | null>> {
  return apiGet<Campagne | null>('/applications/campaigns/current', { revalidate: 60 });
}

export function getPays(): Promise<ApiResult<Pays[]>> {
  return apiGet<Pays[]>('/reference/pays', { revalidate: 86400 });
}

export function getRegions(): Promise<ApiResult<Region[]>> {
  return apiGet<Region[]>('/reference/regions-cameroun', { revalidate: 86400 });
}

export function getDepartements(regionCode: string): Promise<ApiResult<Departement[]>> {
  const qs = regionCode ? `?region=${encodeURIComponent(regionCode)}` : '';
  return apiGet<Departement[]>(`/reference/departements-cameroun${qs}`, { revalidate: 86400 });
}

export function getSpecialites(): Promise<ApiResult<Specialite[]>> {
  return apiGet<Specialite[]>('/reference/specialites', { revalidate: 86400 });
}

export function registerCandidat(
  payload: RegisterCandidatPayload,
): Promise<ApiResult<RegisterCandidatResponse>> {
  return apiPost<RegisterCandidatResponse>('/auth/candidat/register', payload);
}

export function putApplicationsMe(
  body: CandidatureProfile,
  token: string,
): Promise<ApiResult<unknown>> {
  return apiPut<unknown>('/applications/me', body, { token });
}
