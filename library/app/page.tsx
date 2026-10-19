import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { CollectionsGrid } from '@/components/home/CollectionsGrid';
import { HomeHero } from '@/components/home/HomeHero';
import { LatestDocuments } from '@/components/home/LatestDocuments';
import { LegalFeature } from '@/components/home/LegalFeature';
import { MostRead } from '@/components/home/MostRead';
import { StatsBar } from '@/components/home/StatsBar';
import { ThemesTeaser } from '@/components/home/ThemesTeaser';
import { JsonLd, websiteJsonLd } from '@/components/seo/JsonLd';
import { PRIMARY_ACTION, ServiceUnavailable } from '@/components/catalogue/ServiceUnavailable';
import { getMotsCles, getStats, listDocuments } from '@/lib/api/library';
import type { DocumentSummary } from '@/lib/api/types';
import { isBuildPhase, unwrapOrThrow } from '@/lib/api/unwrap';
import { siteUrl } from '@/lib/site';
import { themesCount } from '@/lib/themes';

export const revalidate = 300;

const LATEST_COUNT = 8;
const HERO_SHOWCASE_COUNT = 3;
const MOST_READ_COUNT = 10;

/** Couvertures du héros : les plus consultés, ceux qui ont une vraie couverture d'abord. */
function showcase(popular: DocumentSummary[]): DocumentSummary[] {
  const withCover = popular.filter((d) => d.cover_url);
  return [...withCover, ...popular.filter((d) => !d.cover_url)].slice(0, HERO_SHOWCASE_COUNT);
}

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
 * Accueil : héros + recherche, indicateurs, collections, nouveautés, législation, thèmes, plus lus.
 * Statistiques et listes sont indispensables : si l'un de ces appels échoue, la page lève une
 * exception (`unwrapOrThrow`) — l'ISR garde alors la dernière version valide, sinon `app/error.tsx`.
 * Les mots-clés sont facultatifs : sans eux, les sections Thèmes et raccourcis juridiques sont masquées.
 */
export default async function HomePage(): Promise<JSX.Element> {
  const [statsResult, latestResult, popularResult, motsClesResult, legalMotsClesResult] = await Promise.all([
    getStats(),
    listDocuments({ tri: 'recent' }, 1, LATEST_COUNT),
    listDocuments({ tri: 'populaire' }, 1, MOST_READ_COUNT),
    getMotsCles(),
    getMotsCles({ type: 'texte_loi' }),
  ]);

  // `next build` sans API (CI) : page de repli au lieu d'un build en échec ; remplacée à la
  // première revalidation réussie (une revalidation en échec, elle, la conserve).
  if (isBuildPhase() && [statsResult, latestResult, popularResult].some((r) => !r.ok)) {
    return <BuildFallback />;
  }

  const stats = unwrapOrThrow(statsResult).data;
  const latest = unwrapOrThrow(latestResult).data.slice(0, LATEST_COUNT);
  const popular = unwrapOrThrow(popularResult).data.slice(0, MOST_READ_COUNT);

  const motsCles = motsClesResult.ok ? motsClesResult.data.data : [];
  const legalMotsCles = legalMotsClesResult.ok ? legalMotsClesResult.data.data : [];

  const tMeta = await getTranslations('meta');
  const t = await getTranslations('home');

  return (
    <>
      <JsonLd data={websiteJsonLd({ name: tMeta('siteName'), description: t('metaDescription'), origin: siteUrl().origin })} />
      <HomeHero showcase={showcase(popular)} />
      <StatsBar stats={stats} themes={themesCount(motsCles)} />
      <CollectionsGrid stats={stats} />
      <LatestDocuments docs={latest} />
      <LegalFeature count={stats.by_type.texte_loi ?? 0} motsCles={legalMotsCles} />
      <ThemesTeaser motsCles={motsCles} />
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
