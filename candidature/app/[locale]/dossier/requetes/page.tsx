import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { EspaceRequetes } from '@/components/espace/EspaceRequetes';
import { getMyRequetes } from '@/lib/api/client';
import { getCandidatToken } from '@/lib/auth/session';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';
import { redirect } from '@/navigation';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('espace.nav');

  // Page privée : hors index.
  return { title: t('requetes'), robots: { index: false, follow: false } };
}

export default async function RequetesPage({ searchParams }: { searchParams: { annulee?: string; erreur?: string } }): Promise<JSX.Element> {
  const { candidature, scolarite, consultation } = await chargerEspaceAuditeur();
  const token = await getCandidatToken();
  const donnees = token ? await getMyRequetes(token) : null;
  if (!donnees?.ok) {
    if (donnees?.status === 401) {
      redirect('/auth/session-expired');
    }
    redirect('/dossier');
  }
  const message = searchParams.erreur === '1' ? 'erreur' : searchParams.annulee === '1' ? 'annulee' : null;

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="requetes">
      <EspaceRequetes donnees={donnees.data} consultation={consultation} message={message} />
    </EspaceGabarit>
  );
}
