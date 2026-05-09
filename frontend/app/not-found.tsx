import Link from 'next/link';
import { getTranslations } from 'next-intl/server';

export default async function NotFound(): Promise<JSX.Element> {
  const t = await getTranslations('notFound');
  return (
    <div className="mx-auto max-w-3xl px-6 py-24 text-center">
      <h1 className="font-heading text-6xl font-bold text-[#6B2FA0]">404</h1>
      <p className="mt-3 font-heading text-xl text-[#333]">{t('title')}</p>
      <p className="mt-3 text-[#555]">{t('body')}</p>
      <Link
        href="/"
        className="mt-8 inline-flex h-11 items-center rounded-md bg-[#6B2FA0] px-6 font-medium text-white hover:bg-[#9B59B6] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
      >
        {t('back')}
      </Link>
    </div>
  );
}
