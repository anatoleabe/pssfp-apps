import { EspaceDocuments } from '@/components/espace/EspaceDocuments';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';

export const metadata = { robots: { index: false, follow: false }, title: 'Mes documents' };

export default async function DocumentsPage(): Promise<JSX.Element> {
  const { candidature, scolarite } = await chargerEspaceAuditeur();

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite}>
      <EspaceDocuments candidature={candidature} />
    </EspaceGabarit>
  );
}
