export interface ApiHealth {
  status: 'ok' | 'degraded' | 'down';
  timestamp: string;
  version?: string;
  services?: Record<string, 'ok' | 'down' | 'unknown'>;
}

export interface ApiError {
  message: string;
  code?: string;
  status: number;
}

export type ApiResult<T> =
  | { ok: true; data: T }
  | { ok: false; error: ApiError };
