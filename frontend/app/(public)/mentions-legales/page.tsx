import { getTranslations } from 'next-intl/server';

export const metadata = {
  title: 'Mentions légales',
  description: 'Mentions légales du site institutionnel pssfp.net.',
};

export default async function MentionsLegalesPage(): Promise<JSX.Element> {
  const t = await getTranslations('legal');

  return (
    <article className="mx-auto max-w-3xl px-6 py-12 md:py-16">
      <h1 className="font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl">
        {t('title')}
      </h1>

      <section className="mt-8 space-y-3">
        <h2 className="font-heading text-xl font-semibold text-[#333]">{t('publisher')}</h2>
        <p className="text-[#555]">{t('publisherBody')}</p>
      </section>

      <section className="mt-8 space-y-3">
        <h2 className="font-heading text-xl font-semibold text-[#333]">{t('hosting')}</h2>
        <p className="text-[#555]">{t('hostingBody')}</p>
      </section>

      <section className="mt-8 space-y-3">
        <h2 className="font-heading text-xl font-semibold text-[#333]">{t('intellectual')}</h2>
        <p className="text-[#555]">{t('intellectualBody')}</p>
      </section>
    </article>
  );
}
