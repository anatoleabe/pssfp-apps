import dynamic from 'next/dynamic';
import { HomeActualites } from '@/components/HomeActualites';
import { HomeAccessRapide } from '@/components/HomeAccessRapide';
import { HomeCampusCta } from '@/components/HomeCampusCta';
import { HomeInstitutionalHero } from '@/components/HomeInstitutionalHero';
import { HomePartenaires } from '@/components/HomePartenaires';
import { HomePiliers } from '@/components/HomePiliers';
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
  return (
    <>
      <JsonLd data={organizationJsonLd()} />
      <HomeInstitutionalHero />
      <HomeAccessRapide />
      <HomeStats />
      <HomeSpecialites />
      <HomeActualites />
      <HomePiliers />
      <HomePartenaires />
      <HomeCampusCta />
    </>
  );
}
