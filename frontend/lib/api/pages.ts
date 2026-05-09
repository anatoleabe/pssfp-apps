import type { ApiResult } from './types';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

export interface ApiPage {
  uuid: string;
  slug: string;
  parent_slug: string | null;
  title: string;
  excerpt: string | null;
  body: string | null;
  meta_title: string | null;
  meta_description: string | null;
  og_image_path: string | null;
  status: 'draft' | 'in_review' | 'published' | 'archived';
  published_at: string | null;
  order: number;
  is_in_menu: boolean;
  menu_label: string | null;
}

export interface MenuItem {
  slug: string;
  label: string;
  order: number;
  children?: MenuItem[];
}

async function safeJson<T>(path: string, revalidate?: number): Promise<ApiResult<T>> {
  const url = `${API_BASE_URL}${path}`;
  try {
    const response = await fetch(url, {
      method: 'GET',
      headers: { Accept: 'application/json', 'Accept-Language': 'fr' },
      next: revalidate !== undefined ? { revalidate } : undefined,
    });
    if (!response.ok) {
      return {
        ok: false,
        error: { message: `API ${response.status}`, status: response.status },
      };
    }
    const payload = (await response.json()) as { data?: T } | T;
    const data =
      payload !== null && typeof payload === 'object' && 'data' in payload
        ? ((payload as { data: T }).data)
        : (payload as T);
    return { ok: true, data };
  } catch (error) {
    return {
      ok: false,
      error: {
        message: error instanceof Error ? error.message : 'Erreur réseau',
        status: 0,
      },
    };
  }
}

export function getPageBySlug(slug: string): Promise<ApiResult<ApiPage>> {
  return safeJson<ApiPage>(`/pages/${encodeURIComponent(slug)}`, 300);
}

export function getMenu(): Promise<ApiResult<MenuItem[]>> {
  return safeJson<MenuItem[]>('/menu', 300);
}
