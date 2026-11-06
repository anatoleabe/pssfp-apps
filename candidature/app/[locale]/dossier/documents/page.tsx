import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { EspaceDocuments } from '@/components/espace/EspaceDocuments';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('espace.nav');

  // Page privée : hors index.
  return { title: t('documents'), robots: { index: false, follow: false } };
}

export default async function DocumentsPage(): Promise<JSX.Element> {
  const { candidature, scolarite, consultation } = await chargerEspaceAuditeur();

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="documents">
      <EspaceDocuments candidature={candidature} scolarite={scolarite} consultation={consultation} />
    </EspaceGabarit>
  );
}
