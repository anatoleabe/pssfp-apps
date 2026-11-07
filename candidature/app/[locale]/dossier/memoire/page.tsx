import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { EspaceMemoire } from '@/components/espace/EspaceMemoire';
import { getMyMemoire } from '@/lib/api/client';
import { getCandidatToken } from '@/lib/auth/session';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';
import { redirect } from '@/navigation';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('espace.nav');

  // Page privée : hors index.
  return { title: t('memoire'), robots: { index: false, follow: false } };
}

export default async function MemoirePage({ searchParams }: { searchParams: { erreur?: string } }): Promise<JSX.Element> {
  const { candidature, scolarite, consultation } = await chargerEspaceAuditeur();
  const token = await getCandidatToken();
  const donnees = token ? await getMyMemoire(token) : null;
  if (!donnees?.ok) {
    if (donnees?.status === 401) {
      redirect('/auth/session-expired');
    }
    redirect('/dossier');
  }

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="memoire">
      <EspaceMemoire memoire={donnees.data} consultation={consultation} erreur={searchParams.erreur === '1'} />
    </EspaceGabarit>
  );
}
