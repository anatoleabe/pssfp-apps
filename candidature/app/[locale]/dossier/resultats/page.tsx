import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { EspaceResultats } from '@/components/espace/EspaceResultats';
import { getMyResultats } from '@/lib/api/client';
import { getCandidatToken } from '@/lib/auth/session';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';
import { redirect } from '@/navigation';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('espace.nav');

  // Page privée : hors index.
  return { title: t('resultats'), robots: { index: false, follow: false } };
}

export default async function ResultatsPage({ searchParams }: { searchParams: { document_error?: string } }): Promise<JSX.Element> {
  const { candidature, scolarite } = await chargerEspaceAuditeur();
  const token = await getCandidatToken();
  const resultats = token ? await getMyResultats(token) : null;
  if (!resultats?.ok) {
    if (resultats?.status === 401) {
      redirect('/auth/session-expired');
    }
    redirect('/dossier');
  }

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="resultats">
      <EspaceResultats resultats={resultats.data} erreurDocument={searchParams.document_error === '1'} />
    </EspaceGabarit>
  );
}
