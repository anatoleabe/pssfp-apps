import { getTranslations } from 'next-intl/server';

/** Accueil provisoire : réécrit par la tâche 4 (héros, mosaïque, nouveautés, plus lus). */
export default async function HomePage(): Promise<JSX.Element> {
  const t = await getTranslations('home');
  return (
    <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6">
      <h1 className="font-heading text-pssfp-h1 font-bold text-pssfp-prune">{t('title')}</h1>
      <p className="mt-4 max-w-3xl text-pssfp-lead">{t('intro')}</p>
    </div>
  );
}
