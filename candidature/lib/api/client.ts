import type {
  ApiResult,
  Campagne,
  CandidatureProfile,
  Departement,
  Diplome,
  EmployeurPublicGroup,
  Pays,
  Region,
  RegisterCandidatPayload,
  RegisterCandidatResponse,
  Specialite,
  UniversitePays,
} from './types';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

interface FetchOptions {
  revalidate?: number;
  signal?: AbortSignal;
  token?: string | null;
  headers?: Record<string, string>;
  /**
   * Force `cache: 'no-store'`. Utilisé pour les lectures authentifiées
   * spécifiques au candidat (PII) : ne JAMAIS mettre en Data Cache Next.js
   * une réponse `/applications/me` — sinon un candidat verrait des données
   * périmées après un auto-save (cf. bug « champ rempli affiché manquant »)
   * et les données sensibles resteraient en cache partagé côté serveur.
   */
  noStore?: boolean;
}

async function apiCall<T>(
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  path: string,
  body?: unknown,
  options: FetchOptions = {},
): Promise<ApiResult<T>> {
  // Les tests E2E du portail vérifient aussi le comportement de repli quand
  // l'API Laravel n'est pas démarrée. Court-circuiter les appels SSR évite que
  // Next.js ne remonte des rejets réseau parasites pendant cette recette.
  if (process.env.PSSFP_E2E_OFFLINE === '1' && typeof window === 'undefined') {
    return { ok: false, status: 0, message: 'API indisponible en environnement E2E' };
  }

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
      // `cache: no-store` a priorité (données PII authentifiées) ; sinon
      // `next.revalidate` si fourni ; sinon comportement par défaut Next.js.
      ...(options.noStore
        ? { cache: 'no-store' as const }
        : options.revalidate !== undefined
          ? { next: { revalidate: options.revalidate } }
          : {}),
      // Une API indisponible ne doit jamais suspendre le rendu SSR du portail.
      // Les uploads ont leur propre chemin ; 3 s suffisent pour ces appels JSON.
      signal: options.signal ?? AbortSignal.timeout(3_000),
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
        code?: string;
        errors?: Record<string, string[]>;
      };
      return {
        ok: false,
        status: response.status,
        message: errorPayload.message ?? `API ${response.status}`,
        code: errorPayload.code,
        errors: errorPayload.errors,
      };
    }

    return { ok: true, data: (payload as { data?: T })?.data ?? (payload as T) };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: error instanceof Error ? error.message : 'Erreur réseau',
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

export function getDiplomes(): Promise<ApiResult<Diplome[]>> {
  return apiGet<Diplome[]>('/reference/diplomes', { revalidate: 86400 });
}

export function getUniversites(): Promise<ApiResult<UniversitePays[]>> {
  return apiGet<UniversitePays[]>('/reference/universites', { revalidate: 86400 });
}

export function getEmployeursPublics(): Promise<ApiResult<EmployeurPublicGroup[]>> {
  return apiGet<EmployeurPublicGroup[]>('/reference/employeurs-publics', { revalidate: 86400 });
}

export function registerCandidat(
  payload: RegisterCandidatPayload,
): Promise<ApiResult<RegisterCandidatResponse>> {
  return apiPost<RegisterCandidatResponse>('/auth/candidat/register', payload);
}

export function loginCandidat(payload: {
  phone_e164: string;
  pin: string;
}): Promise<ApiResult<RegisterCandidatResponse>> {
  return apiPost<RegisterCandidatResponse>('/auth/candidat/login', payload);
}

export function forgotPin(payload: {
  phone_e164: string;
  turnstile_token?: string;
}): Promise<ApiResult<unknown>> {
  return apiPost<unknown>('/auth/candidat/forgot-pin', payload);
}

export function verifyOtp(payload: {
  phone_e164: string;
  code: string;
}): Promise<ApiResult<{ pin_reset_token: string; expires_at: string | null }>> {
  return apiPost('/auth/candidat/verify-otp', payload);
}

export function resetPin(
  payload: { pin: string; pin_confirmation: string },
  pinResetToken: string,
): Promise<ApiResult<unknown>> {
  return apiPost<unknown>('/auth/candidat/reset-pin', payload, { token: pinResetToken });
}

export function logoutCandidat(token: string): Promise<ApiResult<unknown>> {
  return apiPost<unknown>('/auth/candidat/logout', {}, { token });
}

export function putApplicationsMe(
  body: CandidatureProfile,
  token: string,
): Promise<ApiResult<unknown>> {
  return apiPut<unknown>('/applications/me', body, { token });
}

export type CandidatureDocumentType =
  | 'diplome'
  | 'acte_naissance'
  | 'releves_notes'
  | 'cv'
  | 'lettre_motivation'
  | 'attestation_employeur'
  | 'autre';

export interface CandidatureDocumentItem {
  uuid: string;
  type: CandidatureDocumentType;
  original_filename: string;
  size: number;
  url: string;
  uploaded_at: string;
}

export interface MyCandidature extends CandidatureProfile {
  uuid: string;
  numero_dossier: string;
  statut: 'postulant' | 'candidat' | 'accepte' | 'refuse';
  submitted_at: string | null;
  reviewed_at: string | null;
  decided_at: string | null;
  withdrawn_at: string | null;
  frais_paye: boolean;
  mode_paiement: string | null;
  reference_paiement: string | null;
  date_paiement: string | null;
  recipisse_available: boolean;
  has_photo: boolean;
  photo_url: string | null;
  documents: CandidatureDocumentItem[];
  campagne: { slug: string; nom: string; closes_at: string | null } | null;
}

export function getMyCandidature(token: string): Promise<ApiResult<MyCandidature>> {
  // no-store : données candidat sensibles + doit toujours refléter le dernier
  // état sauvegardé (auto-save /dossier/edition) sans passer par le Data Cache.
  return apiGet<MyCandidature>('/applications/me', { token, noStore: true });
}

export function submitMyCandidature(
  token: string,
  idempotencyKey?: string,
): Promise<ApiResult<{ recipisse_url?: string }>> {
  const headers = idempotencyKey ? { 'X-Idempotency-Key': idempotencyKey } : undefined;
  return apiPost(
    '/applications/me/submit',
    { confirmation_engagement: true },
    { token, headers },
  );
}

export function withdrawMyCandidature(token: string): Promise<ApiResult<unknown>> {
  return apiPost('/applications/me/withdraw', { confirmation: true }, { token });
}

export interface UploadPhotoResponse {
  photo_path: string;
  photo_url: string;
}

/**
 * POST /v1/applications/me/photo (multipart/form-data).
 *
 * Délègue à fetch directement (pas via apiCall — JSON.stringify détruirait le FormData).
 * Pas de Content-Type set : le browser/Node fetch déduit `multipart/form-data; boundary=...`.
 */
export async function uploadPhoto(
  file: File | Blob,
  token: string,
): Promise<ApiResult<UploadPhotoResponse>> {
  const fd = new FormData();
  fd.append('photo', file);

  const url = `${API_BASE_URL}/applications/me/photo`;
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': 'fr',
    Authorization: `Bearer ${token}`,
  };

  try {
    const response = await fetch(url, { method: 'POST', headers, body: fd });
    let payload: unknown = null;
    if (response.status !== 204) {
      try {
        payload = await response.json();
      } catch {
        payload = null;
      }
    }
    if (!response.ok) {
      const errorPayload = (payload ?? {}) as { message?: string; errors?: Record<string, string[]> };
      return {
        ok: false,
        status: response.status,
        message: errorPayload.message ?? `API ${response.status}`,
        errors: errorPayload.errors,
      };
    }
    const data = (payload as { data?: UploadPhotoResponse })?.data ?? (payload as UploadPhotoResponse);
    return { ok: true, data };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: error instanceof Error ? error.message : 'Erreur réseau',
    };
  }
}

export async function deleteMyPhoto(token: string): Promise<ApiResult<null>> {
  const url = `${API_BASE_URL}/applications/me/photo`;
  try {
    const response = await fetch(url, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    });
    if (!response.ok) {
      let message = `API ${response.status}`;
      try {
        const body = (await response.json()) as { message?: string };
        if (body?.message) {
          message = body.message;
        }
      } catch {
        // ignore
      }
      return { ok: false, status: response.status, message };
    }
    return { ok: true, data: null };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: error instanceof Error ? error.message : 'Erreur réseau',
    };
  }
}

/**
 * POST /v1/applications/me/documents (multipart/form-data).
 *
 * Pièce justificative optionnelle — même limitation que uploadPhoto : pas
 * de Content-Type explicite, laissé au fetch/boundary automatique.
 */
export async function uploadCandidatureDocument(
  file: File | Blob,
  type: CandidatureDocumentType,
  token: string,
): Promise<ApiResult<CandidatureDocumentItem>> {
  const fd = new FormData();
  fd.append('fichier', file);
  fd.append('type', type);

  const url = `${API_BASE_URL}/applications/me/documents`;
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': 'fr',
    Authorization: `Bearer ${token}`,
  };

  try {
    const response = await fetch(url, { method: 'POST', headers, body: fd });
    let payload: unknown = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }
    if (!response.ok) {
      const errorPayload = (payload ?? {}) as { message?: string; errors?: Record<string, string[]> };
      return {
        ok: false,
        status: response.status,
        message: errorPayload.message ?? `API ${response.status}`,
        errors: errorPayload.errors,
      };
    }
    const data = (payload as { data?: CandidatureDocumentItem })?.data ?? (payload as CandidatureDocumentItem);
    return { ok: true, data };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: error instanceof Error ? error.message : 'Erreur réseau',
    };
  }
}

export async function deleteCandidatureDocument(
  uuid: string,
  token: string,
): Promise<ApiResult<null>> {
  const url = `${API_BASE_URL}/applications/me/documents/${uuid}`;
  try {
    const response = await fetch(url, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    });
    if (!response.ok) {
      let message = `API ${response.status}`;
      try {
        const body = (await response.json()) as { message?: string };
        if (body?.message) {
          message = body.message;
        }
      } catch {
        // ignore
      }
      return { ok: false, status: response.status, message };
    }
    return { ok: true, data: null };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: error instanceof Error ? error.message : 'Erreur réseau',
    };
  }
}
