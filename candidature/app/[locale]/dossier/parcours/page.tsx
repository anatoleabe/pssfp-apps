import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { EspaceParcours } from '@/components/espace/EspaceParcours';
import { getMyParcours } from '@/lib/api/client';
import { getCandidatToken } from '@/lib/auth/session';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';
import { redirect } from '@/navigation';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('espace.nav');

  // Page privée : hors index.
  return { title: t('parcours'), robots: { index: false, follow: false } };
}

export default async function ParcoursPage(): Promise<JSX.Element> {
  const { candidature, scolarite } = await chargerEspaceAuditeur();
  const token = await getCandidatToken();
  const parcours = token ? await getMyParcours(token) : null;
  if (!parcours?.ok) {
    if (parcours?.status === 401) {
      redirect('/auth/session-expired');
    }
    redirect('/dossier');
  }

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="parcours">
      <EspaceParcours parcours={parcours.data} />
    </EspaceGabarit>
  );
}
