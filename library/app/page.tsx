import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { CollectionsMosaic } from '@/components/home/CollectionsMosaic';
import { HomeHero } from '@/components/home/HomeHero';
import { LatestDocuments } from '@/components/home/LatestDocuments';
import { MostRead } from '@/components/home/MostRead';
import { JsonLd, websiteJsonLd } from '@/components/seo/JsonLd';
import { getStats, listDocuments } from '@/lib/api/library';
import { siteUrl } from '@/lib/site';

export const revalidate = 300;

const LATEST_COUNT = 6;
const HERO_LATEST_COUNT = 3;
const MOST_READ_COUNT = 10;

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('home');
  const tMeta = await getTranslations('meta');
  return {
    title: t('metaTitle'),
    description: t('metaDescription'),
    alternates: { canonical: '/' },
    openGraph: {
      title: tMeta('titleDefault'),
      description: t('metaDescription'),
      url: '/',
      siteName: tMeta('siteName'),
      locale: 'fr_FR',
      type: 'website',
    },
  };
}

/** Accueil : héros + recherche, mosaïque des collections, nouveautés, plus lus. Chaque section survit seule à l'échec de son appel. */
export default async function HomePage(): Promise<JSX.Element> {
  const [statsResult, latestResult, popularResult] = await Promise.all([
    getStats(),
    listDocuments({ tri: 'recent' }, 1, LATEST_COUNT),
    listDocuments({ tri: 'populaire' }, 1, MOST_READ_COUNT),
  ]);
  const stats = statsResult.ok ? statsResult.data.data : null;
  const latest = latestResult.ok ? latestResult.data.data.slice(0, LATEST_COUNT) : null;
  const popular = popularResult.ok ? popularResult.data.data.slice(0, MOST_READ_COUNT) : null;

  const tMeta = await getTranslations('meta');
  const t = await getTranslations('home');

  return (
    <>
      <JsonLd data={websiteJsonLd({ name: tMeta('siteName'), description: t('metaDescription'), origin: siteUrl().origin })} />
      <HomeHero stats={stats} latest={latest ? latest.slice(0, HERO_LATEST_COUNT) : null} />
      <CollectionsMosaic stats={stats} />
      <LatestDocuments docs={latest} />
      <MostRead docs={popular} />
    </>
  );
}
