import { Button } from '@pssfp/ui';
import { getTranslations } from 'next-intl/server';
import { getApiHealth } from '@/lib/api/client';

export default async function HomePage() {
  const t = await getTranslations('home');
  const health = await getApiHealth();
  const apiOk = health.ok && health.data.status === 'ok';

  return (
    <div className="mx-auto max-w-7xl px-6 py-16">
      <section aria-labelledby="hero-heading" className="space-y-6">
        <h1
          id="hero-heading"
          className="font-heading text-4xl font-bold text-[#6B2FA0] md:text-6xl"
        >
          {t('title')}
        </h1>
        <p className="max-w-3xl text-lg text-[#333333]">{t('intro')}</p>
        <div className="flex gap-4">
          <Button variant="primary" size="lg">
            {t('apiHealthLabel')}
          </Button>
        </div>
      </section>

      <section
        aria-label={t('apiHealthLabel')}
        className="mt-12 rounded-lg border border-gray-200 bg-[#F5F5F5] p-6"
      >
        <h2 className="font-heading text-2xl font-bold text-[#333333]">
          {t('apiHealthLabel')}
        </h2>
        <p className="mt-2 text-base">
          <span
            data-testid="api-health-status"
            className={apiOk ? 'text-[#2E7D32]' : 'text-[#C62828]'}
          >
            {apiOk ? t('apiHealthOk') : t('apiHealthError')}
          </span>
        </p>
        {!health.ok && (
          <p className="mt-2 text-sm text-[#666666]" data-testid="api-health-error">
            {health.error.message}
          </p>
        )}
      </section>
    </div>
  );
}
