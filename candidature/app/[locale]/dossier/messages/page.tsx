import { getTranslations } from 'next-intl/server';
import { DossierMessages } from '@/components/dossier/DossierMessages';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';

export const metadata = { robots: { index: false, follow: false }, title: 'Mes messages' };

export default async function MessagesPage(): Promise<JSX.Element> {
  const { candidature, scolarite } = await chargerEspaceAuditeur();
  const t = await getTranslations('espace.messages');
  const aucun = (candidature.messages ?? []).length === 0;

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite}>
      {aucun ? (
        <p data-testid="messages-vide" className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 text-sm text-[#5C5C5C] shadow-pssfp-soft">{t('aucun')}</p>
      ) : (
        <DossierMessages messages={candidature.messages} />
      )}
    </EspaceGabarit>
  );
}
