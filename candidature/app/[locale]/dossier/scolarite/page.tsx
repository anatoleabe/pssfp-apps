import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { DossierScolariteCard } from '@/components/dossier/DossierScolariteCard';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('espace.nav');

  // Page privée : hors index.
  return { title: t('scolarite'), robots: { index: false, follow: false } };
}

export default async function ScolaritePage(): Promise<JSX.Element> {
  const { candidature, scolarite } = await chargerEspaceAuditeur();

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="scolarite">
      <DossierScolariteCard scolarite={scolarite} />
    </EspaceGabarit>
  );
}
