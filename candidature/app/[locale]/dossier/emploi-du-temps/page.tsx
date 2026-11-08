import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { EspaceEmploiDuTemps } from '@/components/espace/EspaceEmploiDuTemps';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { getMyEmploiDuTemps } from '@/lib/api/client';
import { getCandidatToken } from '@/lib/auth/session';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';
import { redirect } from '@/navigation';

const DATE = /^\d{4}-\d{2}-\d{2}$/;

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('espace.nav');

  // Page privée : hors index.
  return { title: t('emploi'), robots: { index: false, follow: false } };
}

export default async function EmploiDuTempsPage({ searchParams }: { searchParams: { semaine?: string } }): Promise<JSX.Element> {
  const { candidature, scolarite } = await chargerEspaceAuditeur();
  const token = await getCandidatToken();
  const semaine = searchParams.semaine && DATE.test(searchParams.semaine) ? searchParams.semaine : undefined;
  const edt = token ? await getMyEmploiDuTemps(token, semaine) : null;
  if (!edt?.ok) {
    if (edt?.status === 401) {
      redirect('/auth/session-expired');
    }
    redirect('/dossier');
  }

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="emploi">
      <EspaceEmploiDuTemps edt={edt.data} />
    </EspaceGabarit>
  );
}
