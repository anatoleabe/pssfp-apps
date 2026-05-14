import dynamic from 'next/dynamic';
import { HomeActualites } from '@/components/HomeActualites';
import { HomeAccessRapide } from '@/components/HomeAccessRapide';
import { HomeEngagements } from '@/components/HomeEngagements';
import { HomeHero } from '@/components/HomeHero';
import { HomePartenaires } from '@/components/HomePartenaires';
import { HomePiliers } from '@/components/HomePiliers';
import { HomeShowcase } from '@/components/HomeShowcase';
import { HomeSpecialites } from '@/components/HomeSpecialites';
import { JsonLd, organizationJsonLd } from '@/components/JsonLd';

// HomeStats est le seul composant home en `'use client'` (Framer Motion spring
// pour NumberTicker + BlurFade). On le charge dynamiquement : le JS Framer
// n'est pas dans le bundle initial, ce qui améliore TTI / LCP de la home.
// Les autres sections sont des Server Components — pas besoin de dynamic.
const HomeStats = dynamic(() =>
  import('@/components/HomeStats').then((m) => ({ default: m.HomeStats })),
);

export const revalidate = 300; // ISR 5 min — les actualités featured peuvent changer.

export const metadata = {
  title: 'Accueil',
  description:
    'PSSFP — Programme Supérieur de Spécialisation en Finances Publiques. Formations supérieures en finances publiques au Campus de Messa, Yaoundé.',
};

export default async function HomePage(): Promise<JSX.Element> {
  const heroVariant = process.env.NEXT_PUBLIC_HERO_VARIANT ?? 'showcase';

  return (
    <>
      <JsonLd data={organizationJsonLd()} />
      {heroVariant === 'legacy' ? <HomeHero /> : <HomeShowcase />}
      {/* Sprint S5.1 — bandeau institutionnel 4 piliers (ADR-0008 maquette identité 2026) */}
      <HomePiliers />
      <HomeStats />
      <HomeSpecialites />
      {/* Sprint S5.1 — section éditoriale "Nos engagements" */}
      <HomeEngagements />
      <HomeActualites />
      <HomePartenaires />
      <HomeAccessRapide />
    </>
  );
}
