import { HomeAccessRapide } from '@/components/HomeAccessRapide';
import { HomeActualites } from '@/components/HomeActualites';
import { HomeHero } from '@/components/HomeHero';
import { HomePartenaires } from '@/components/HomePartenaires';
import { HomeSpecialites } from '@/components/HomeSpecialites';
import { HomeStats } from '@/components/HomeStats';
import { JsonLd, organizationJsonLd } from '@/components/JsonLd';

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
      <HomeHero />
      <HomeStats />
      <HomeSpecialites />
      <HomeActualites />
      <HomePartenaires />
      <HomeAccessRapide />
    </>
  );
}
