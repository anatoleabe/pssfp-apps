import Link from 'next/link';
import { useFormatter, useTranslations } from 'next-intl';

interface LegalDocumentProps {
  title: string;
  /** Date ISO (YYYY-MM-DD) de dernière révision du document. */
  updatedAt: string;
  children: React.ReactNode;
}

/**
 * Gabarit typographique partagé des pages légales candidat (CGU,
 * confidentialité). Volontairement sobre et lisible — pas de dépendance au
 * plugin @tailwindcss/typography.
 */
export function LegalDocument({ title, updatedAt, children }: LegalDocumentProps): JSX.Element {
  const t = useTranslations('legal');
  const format = useFormatter();

  return (
    <article className="mx-auto max-w-3xl px-6 py-16">
      <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">{title}</h1>
      <p className="mt-2 text-sm text-[#595959]">
        {t('updatedAtLabel')}{' '}
        <time dateTime={updatedAt}>
          {format.dateTime(new Date(`${updatedAt}T00:00:00`), { dateStyle: 'long' })}
        </time>
      </p>

      <div
        className="mt-8 space-y-5 text-[15px] leading-relaxed text-[#333] [&_a]:text-[#4A2E67] [&_a]:underline hover:[&_a]:text-[#5C3A7E] [&_h2]:mt-8 [&_h2]:font-heading [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-[#4A2E67] [&_li]:ml-1 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-6"
      >
        {children}
      </div>

      <div className="mt-12 border-t border-gray-100 pt-6 text-sm">
        <Link href="/" className="text-[#4A2E67] underline hover:text-[#5C3A7E]">
          <span aria-hidden="true">← </span>
          {t('backHome')}
        </Link>
      </div>
    </article>
  );
}
