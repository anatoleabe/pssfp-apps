import { DossierAccesCard } from '@/components/dossier/DossierAccesCard';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';

export const metadata = { robots: { index: false, follow: false }, title: 'Mes accès' };

export default async function AccesPage(): Promise<JSX.Element> {
  const { candidature, scolarite, consultation } = await chargerEspaceAuditeur();

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite}>
      <DossierAccesCard scolarite={scolarite} consultation={consultation} />
    </EspaceGabarit>
  );
}
