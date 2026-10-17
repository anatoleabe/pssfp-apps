import { Coins, Download, ExternalLink, EyeOff, Lock, type LucideIcon } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { DocumentDetail } from '@/lib/api/types';
import { fileSizeParts, formatPrice } from '@/lib/format';
import { downloadHref } from '@/lib/routes';

const BUTTON =
  'inline-flex items-center justify-center gap-2 rounded-pssfp-button bg-pssfp-prune px-5 py-3 font-ui text-sm font-medium text-white shadow-pssfp-soft transition-colors hover:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2';

/** Seuls http(s) sont suivis ; sinon on passe par la redirection de l'API. */
function safeExternalUrl(url: string | null): string | null {
  if (!url) return null;
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.toString() : null;
  } catch {
    return null;
  }
}

interface NoticeProps {
  Icon: LucideIcon;
  tone: string;
  title: string;
  lines: string[];
}

function Notice({ Icon, tone, title, lines }: NoticeProps): JSX.Element {
  return (
    <div className={`flex gap-3 rounded-pssfp-card border p-4 ${tone}`}>
      <Icon size={20} strokeWidth={1.75} aria-hidden="true" className="mt-0.5 shrink-0" />
      <div className="space-y-1">
        <p className="font-ui text-sm font-semibold">{title}</p>
        {lines.map((line) => (
          <p key={line} className="text-sm text-pssfp-graphite">
            {line}
          </p>
        ))}
      </div>
    </div>
  );
}

interface AccessActionProps {
  doc: DocumentDetail;
}

/** Action principale de la fiche, selon la décision d'accès calculée par l'API. */
export function AccessAction({ doc }: AccessActionProps): JSX.Element {
  const t = useTranslations('access.action');
  const { reason } = doc.access;

  const content = ((): JSX.Element => {
    switch (reason) {
      case 'public': {
        const size = doc.file_size_bytes ? fileSizeParts(doc.file_size_bytes) : null;
        const label = size
          ? t('downloadWithSize', { size: t(size.unit === 'mo' ? 'sizeMo' : 'sizeKo', { value: size.value }) })
          : t('download');
        return (
          <a href={downloadHref(doc.uuid)} className={BUTTON}>
            <Download size={16} aria-hidden="true" />
            {label}
          </a>
        );
      }
      case 'external':
        return (
          <a href={safeExternalUrl(doc.external_url) ?? downloadHref(doc.uuid)} target="_blank" rel="noopener noreferrer" className={BUTTON}>
            <ExternalLink size={16} aria-hidden="true" />
            {t('external')}
            <span className="sr-only"> {t('newWindow')}</span>
          </a>
        );
      case 'reserved':
        return <Notice Icon={Lock} tone="border-pssfp-lavande/60 bg-[#ECE6F3] text-pssfp-prune" title={t('reservedTitle')} lines={[t('reservedBody')]} />;
      case 'paid':
        return (
          <Notice
            Icon={Coins}
            tone="border-pssfp-or/60 bg-[#F6ECD9] text-pssfp-or-dark"
            title={t('paidTitle')}
            lines={[...(doc.price_xaf !== null ? [t('paidPrice', { price: formatPrice(doc.price_xaf) })] : []), t('paidBody')]}
          />
        );
      default:
        return <Notice Icon={EyeOff} tone="border-pssfp-graphite/20 bg-white text-pssfp-graphite" title={t('unpublishedTitle')} lines={[t('unpublishedBody')]} />;
    }
  })();

  return (
    <div data-testid="access-action" data-reason={reason}>
      {content}
    </div>
  );
}
