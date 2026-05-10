/**
 * Helper pour construire les URLs MinIO du bucket pssfp-media (cf. spec sprint S5 PR V).
 *
 * En dev : `http://localhost:9000/pssfp-media/<path>`.
 * En prod : `https://media.pssfp.net/<path>` (Caddy reverse-proxy MinIO).
 */
const MEDIA_BASE_URL =
  process.env.NEXT_PUBLIC_MEDIA_URL ?? 'http://localhost:9000/pssfp-media';

export function mediaUrl(path: string): string {
  const clean = path.startsWith('/') ? path.slice(1) : path;
  return `${MEDIA_BASE_URL}/${clean}`;
}
