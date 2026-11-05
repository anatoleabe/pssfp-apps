import { DossierProfileSummary } from '@/components/dossier/DossierProfileSummary';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';

export const metadata = { robots: { index: false, follow: false }, title: 'Mon profil' };

export default async function ProfilPage(): Promise<JSX.Element> {
  const { candidature, scolarite } = await chargerEspaceAuditeur();

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite}>
      <DossierProfileSummary candidature={candidature} readOnly />
    </EspaceGabarit>
  );
}
