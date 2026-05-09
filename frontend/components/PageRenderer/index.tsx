import { marked } from 'marked';
import sanitizeHtml from 'sanitize-html';

interface PageRendererProps {
  body: string;
  className?: string;
}

/**
 * Rend un body Markdown CMS en HTML sanitizé (cf. spec module 1 PR K).
 *
 * Pipeline server-side :
 *   markdown (marked) → HTML brut → sanitize-html whitelist → dangerouslySetInnerHTML
 *
 * Whitelist tags = h1-h6, p, ul/ol/li, blockquote, code, pre, table, a, img.
 * Tous les <script>, <iframe>, <style>, attributs `on*`, `javascript:` sont
 * supprimés par sanitize-html (whitelist explicite).
 *
 * `sanitize-html` est pur server-side (pas de JSDOM bundlé, contrairement à
 * isomorphic-dompurify qui casse le build Next.js sur Vercel/standalone).
 */
export function PageRenderer({ body, className = '' }: PageRendererProps): JSX.Element {
  marked.use({ breaks: false, gfm: true });
  const html = marked.parse(body, { async: false }) as string;

  const safeHtml = sanitizeHtml(html, {
    allowedTags: [
      'p', 'br', 'strong', 'em', 'b', 'i', 'a', 'ul', 'ol', 'li', 'blockquote',
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'code', 'hr',
      'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img',
    ],
    allowedAttributes: {
      a: ['href', 'target', 'rel', 'title'],
      img: ['src', 'alt', 'title', 'loading'],
      th: ['colspan', 'rowspan'],
      td: ['colspan', 'rowspan'],
    },
    allowedSchemes: ['http', 'https', 'mailto', 'tel'],
    transformTags: {
      a: sanitizeHtml.simpleTransform('a', { rel: 'noopener noreferrer' }, true),
    },
  });

  return (
    <article
      data-testid="page-content"
      className={`prose prose-pssfp max-w-none ${className}`}
      dangerouslySetInnerHTML={{ __html: safeHtml }}
    />
  );
}
