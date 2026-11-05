import { DossierScolariteCard } from '@/components/dossier/DossierScolariteCard';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';

export const metadata = { robots: { index: false, follow: false }, title: 'Ma scolarité' };

export default async function ScolaritePage(): Promise<JSX.Element> {
  const { candidature, scolarite } = await chargerEspaceAuditeur();

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite}>
      <DossierScolariteCard scolarite={scolarite} />
    </EspaceGabarit>
  );
}
