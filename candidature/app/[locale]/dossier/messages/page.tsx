import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { DossierMessages } from '@/components/dossier/DossierMessages';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('espace.nav');

  // Page privée : hors index.
  return { title: t('messages'), robots: { index: false, follow: false } };
}

export default async function MessagesPage(): Promise<JSX.Element> {
  const { candidature, scolarite } = await chargerEspaceAuditeur();
  const t = await getTranslations('espace.messages');
  const tn = await getTranslations('espace.nav');
  const aucun = (candidature.messages ?? []).length === 0;

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="messages">
      {aucun ? (
        <section aria-labelledby="messages-vide-heading" data-testid="messages-vide" className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7">
          <h2 id="messages-vide-heading" className="font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">{tn('messages')}</h2>
          <p className="mt-3 text-sm text-[#5C5C5C]">{t('aucun')}</p>
        </section>
      ) : (
        <DossierMessages messages={candidature.messages} />
      )}
    </EspaceGabarit>
  );
}
