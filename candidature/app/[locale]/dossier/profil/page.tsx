import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { DossierProfileSummary } from '@/components/dossier/DossierProfileSummary';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('espace.nav');

  // Page privée : hors index.
  return { title: t('profil'), robots: { index: false, follow: false } };
}

export default async function ProfilPage(): Promise<JSX.Element> {
  const { candidature, scolarite } = await chargerEspaceAuditeur();

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="profil">
      <DossierProfileSummary candidature={candidature} readOnly />
    </EspaceGabarit>
  );
}
