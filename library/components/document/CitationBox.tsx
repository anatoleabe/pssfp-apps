'use client';

import { useId, useState } from 'react';
import { Copy } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { DocumentSummary } from '@/lib/api/types';
import { documentHref } from '@/lib/routes';
import { siteUrl } from '@/lib/site';

interface CitationBoxProps {
  doc: DocumentSummary;
}

type CopyState = 'idle' | 'copied' | 'failed';

/** Citation APA : Auteur1, Auteur2 (Année). Titre. Type. Bibliothèque PSSFP. URL */
export function CitationBox({ doc }: CitationBoxProps): JSX.Element {
  const t = useTranslations();
  const headingId = useId();
  const [state, setState] = useState<CopyState>('idle');

  const values = {
    authors: doc.authors.join(', '),
    year: doc.year !== null ? String(doc.year) : t('document.noDate'),
    title: doc.title,
    type: t(`types.${doc.type}.one`),
    publisher: t('citation.publisher'),
    url: new URL(documentHref(doc.slug), siteUrl()).toString(),
  };
  const citation = t(doc.authors.length > 0 ? 'citation.withAuthors' : 'citation.withoutAuthors', values);

  const copy = async (): Promise<void> => {
    setState('idle');
    try {
      await navigator.clipboard.writeText(citation);
      setState('copied');
    } catch {
      setState('failed');
    }
  };

  return (
    <section
      data-testid="citation-box"
      aria-labelledby={headingId}
      className="rounded-pssfp-card border border-pssfp-lavande/40 bg-white p-5 shadow-pssfp-soft"
    >
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id={headingId} className="font-heading text-2xl font-semibold text-pssfp-prune">
          {t('citation.title')}
        </h2>
        <p className="font-ui text-xs font-medium uppercase tracking-[0.14em] text-pssfp-bleu-petrole">{t('citation.format')}</p>
      </div>
      <p className="mt-3 break-words border-l-2 border-pssfp-or pl-4 text-sm leading-relaxed text-pssfp-graphite">{citation}</p>
      <div className="mt-4 flex flex-wrap items-center gap-3">
        <button
          type="button"
          onClick={() => void copy()}
          className="inline-flex items-center gap-2 rounded-pssfp-button border border-pssfp-prune/30 bg-white px-4 py-2 font-ui text-sm font-medium text-pssfp-prune transition-colors hover:border-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2"
        >
          <Copy size={16} aria-hidden="true" />
          {t('citation.copy')}
        </button>
        <p role="status" aria-live="polite" className="font-ui text-sm text-pssfp-graphite">
          {state === 'copied' ? t('citation.copied') : state === 'failed' ? t('citation.copyFailed') : ''}
        </p>
      </div>
    </section>
  );
}
