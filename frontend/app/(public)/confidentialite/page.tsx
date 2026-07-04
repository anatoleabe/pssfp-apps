import { getTranslations } from 'next-intl/server';

export const metadata = {
  title: 'Politique de confidentialité',
  description: 'Politique de confidentialité et gestion des données personnelles sur pssfp.org.',
};

export default async function ConfidentialitePage(): Promise<JSX.Element> {
  const t = await getTranslations('privacy');

  return (
    <article className="mx-auto max-w-3xl px-6 py-12 md:py-16">
      <h1 className="font-heading text-3xl font-bold text-[#4A2E67] md:text-4xl">
        {t('title')}
      </h1>

      <p className="mt-6 text-[#555]">{t('intro')}</p>

      <section className="mt-8 space-y-3">
        <h2 className="font-heading text-xl font-semibold text-[#333]">{t('dataTitle')}</h2>
        <p className="text-[#555]">{t('dataBody')}</p>
      </section>

      <section className="mt-8 space-y-3">
        <h2 className="font-heading text-xl font-semibold text-[#333]">{t('rightsTitle')}</h2>
        <p className="text-[#555]">{t('rightsBody')}</p>
      </section>
    </article>
  );
}
