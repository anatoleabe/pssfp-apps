import type { ApiResult } from './types';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

export interface ApiArticle {
  uuid: string;
  slug: string;
  title: string;
  excerpt: string | null;
  body: string | null;
  featured_image_path: string | null;
  category: string | null;
  category_label: string | null;
  is_pinned: boolean;
  published_at: string | null;
}

export interface PaginatedArticles {
  data: ApiArticle[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
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
    const payload = (await response.json()) as T;
    return { ok: true, data: payload };
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

export function listArticles(params: { page?: number; category?: string; featured?: boolean } = {}): Promise<
  ApiResult<PaginatedArticles>
> {
  const qs = new URLSearchParams();
  if (params.page) qs.set('page', String(params.page));
  if (params.category) qs.set('category', params.category);
  if (params.featured) qs.set('featured', 'true');
  const suffix = qs.toString() ? `?${qs.toString()}` : '';
  return safeJson<PaginatedArticles>(`/articles${suffix}`, 300);
}

export async function getArticleBySlug(slug: string): Promise<ApiResult<ApiArticle>> {
  const r = await safeJson<{ data: ApiArticle }>(`/articles/${encodeURIComponent(slug)}`, 300);
  if (!r.ok) return r;
  return { ok: true, data: r.data.data };
}
