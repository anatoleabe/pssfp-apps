import { getTranslations } from 'next-intl/server';
import { getApiHealth } from '@/lib/api/client';
import { SearchBarPlaceholder } from '@/components/SearchBarPlaceholder';

export default async function HomePage() {
  const t = await getTranslations('home');
  const health = await getApiHealth();
  const apiOk = health.ok && health.data.status === 'ok';

  return (
    <div className="mx-auto max-w-7xl px-6 py-16">
      <h1 className="font-heading text-4xl font-bold text-[#4A2E67] md:text-5xl">
        {t('title')}
      </h1>
      <p className="mt-4 max-w-3xl text-lg text-[#333333]">{t('intro')}</p>

      <SearchBarPlaceholder placeholder={t('searchPlaceholder')} />

      <section
        aria-label={t('apiHealthLabel')}
        className="mt-12 rounded-lg border border-gray-200 bg-[#F5F5F5] p-6"
      >
        <h2 className="font-heading text-2xl font-bold text-[#333333]">
          {t('apiHealthLabel')}
        </h2>
        <p className="mt-2">
          <span
            data-testid="api-health-status"
            className={apiOk ? 'text-[#2E7D32]' : 'text-[#C62828]'}
          >
            {apiOk ? t('apiHealthOk') : t('apiHealthError')}
          </span>
        </p>
      </section>
    </div>
  );
}
