import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { CalendrierAnnee } from '@/components/espace/CalendrierAnnee';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { EspaceParcours } from '@/components/espace/EspaceParcours';
import { getMyAssiduite, getMyCalendrier, getMyParcours } from '@/lib/api/client';
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
  const [parcours, assiduite, calendrier] = token
    ? await Promise.all([getMyParcours(token), getMyAssiduite(token), getMyCalendrier(token)])
    : [null, null, null];
  if (!parcours?.ok) {
    if (parcours?.status === 401) {
      redirect('/auth/session-expired');
    }
    redirect('/dossier');
  }

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="parcours">
      <div className="grid min-w-0 gap-6">
        <EspaceParcours parcours={parcours.data} assiduite={assiduite?.ok ? assiduite.data : []} />
        {calendrier?.ok && calendrier.data && <CalendrierAnnee calendrier={calendrier.data} />}
      </div>
    </EspaceGabarit>
  );
}
