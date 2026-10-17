import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { CollectionsMosaic } from '@/components/home/CollectionsMosaic';
import { HomeHero } from '@/components/home/HomeHero';
import { LatestDocuments } from '@/components/home/LatestDocuments';
import { MostRead } from '@/components/home/MostRead';
import { JsonLd, websiteJsonLd } from '@/components/seo/JsonLd';
import { PRIMARY_ACTION, ServiceUnavailable } from '@/components/catalogue/ServiceUnavailable';
import { getStats, listDocuments } from '@/lib/api/library';
import { isBuildPhase, unwrapOrThrow } from '@/lib/api/unwrap';
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

/**
 * Accueil : héros + recherche, mosaïque des collections, nouveautés, plus lus.
 * Les trois appels sont indispensables : si l'un échoue, la page lève une exception
 * (`unwrapOrThrow`) — l'ISR garde alors la dernière version valide, sinon `app/error.tsx`.
 */
export default async function HomePage(): Promise<JSX.Element> {
  const [statsResult, latestResult, popularResult] = await Promise.all([
    getStats(),
    listDocuments({ tri: 'recent' }, 1, LATEST_COUNT),
    listDocuments({ tri: 'populaire' }, 1, MOST_READ_COUNT),
  ]);

  // `next build` sans API (CI) : page de repli au lieu d'un build en échec ; remplacée à la
  // première revalidation réussie (une revalidation en échec, elle, la conserve).
  if (isBuildPhase() && [statsResult, latestResult, popularResult].some((r) => !r.ok)) {
    return <BuildFallback />;
  }

  const stats = unwrapOrThrow(statsResult).data;
  const latest = unwrapOrThrow(latestResult).data.slice(0, LATEST_COUNT);
  const popular = unwrapOrThrow(popularResult).data.slice(0, MOST_READ_COUNT);

  const tMeta = await getTranslations('meta');
  const t = await getTranslations('home');

  return (
    <>
      <JsonLd data={websiteJsonLd({ name: tMeta('siteName'), description: t('metaDescription'), origin: siteUrl().origin })} />
      <HomeHero stats={stats} latest={latest.slice(0, HERO_LATEST_COUNT)} />
      <CollectionsMosaic stats={stats} />
      <LatestDocuments docs={latest} />
      <MostRead docs={popular} />
    </>
  );
}

async function BuildFallback(): Promise<JSX.Element> {
  const t = await getTranslations('errorPage');
  return (
    <ServiceUnavailable
      action={
        <a href="/" className={PRIMARY_ACTION}>
          {t('retry')}
        </a>
      }
    />
  );
}
