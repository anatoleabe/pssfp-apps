import { getTranslations } from 'next-intl/server';
import Link from 'next/link';

export default async function HomePage() {
  const t = await getTranslations('home');
  return (
    <div className="mx-auto max-w-5xl px-6 py-16">
      <section aria-labelledby="hero-heading" className="space-y-6">
        <h1
          id="hero-heading"
          className="font-heading text-4xl font-bold text-[#6B2FA0] md:text-5xl"
        >
          {t('title')}
        </h1>
        <p className="max-w-3xl text-lg text-[#333333]">{t('intro')}</p>

        <div className="flex flex-wrap gap-4">
          <Link
            href="/login"
            className="rounded-md bg-[#6B2FA0] px-6 py-3 font-medium text-white hover:bg-[#9B59B6]"
          >
            {t('ctaCreateAccount')}
          </Link>
          <Link
            href="/login"
            className="rounded-md border border-[#6B2FA0] px-6 py-3 font-medium text-[#6B2FA0] hover:bg-[#EDE7F6]"
          >
            {t('ctaLogin')}
          </Link>
        </div>
      </section>

      <section className="mt-12 rounded-lg border border-[#C9A227]/40 bg-[#FFFBEA] p-6">
        <p className="text-sm text-[#333333]">{t('feeNotice')}</p>
      </section>
    </div>
  );
}
