import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { DossierAccesCard } from '@/components/dossier/DossierAccesCard';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('espace.nav');

  // Page privée : hors index.
  return { title: t('acces'), robots: { index: false, follow: false } };
}

export default async function AccesPage(): Promise<JSX.Element> {
  const { candidature, scolarite, consultation } = await chargerEspaceAuditeur();

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="acces">
      <DossierAccesCard scolarite={scolarite} consultation={consultation} />
    </EspaceGabarit>
  );
}
