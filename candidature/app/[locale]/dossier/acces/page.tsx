import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { DossierAccesCard, type ErreurLettreAcces } from '@/components/dossier/DossierAccesCard';
import { EspaceGabarit } from '@/components/espace/EspaceGabarit';
import { chargerEspaceAuditeur } from '@/lib/espace/charger';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('espace.nav');

  // Page privée : hors index.
  return { title: t('acces'), robots: { index: false, follow: false } };
}

const ERREURS_LETTRE: readonly ErreurLettreAcces[] = ['non_prete', 'indisponible', 'limite', 'echec'];

interface AccesPageProps {
  searchParams: Promise<{ lettre_acces_error?: string }>;
}

export default async function AccesPage({ searchParams }: AccesPageProps): Promise<JSX.Element> {
  const { candidature, scolarite, consultation } = await chargerEspaceAuditeur();
  // Valeur de l'URL : seules les clés connues atteignent la traduction.
  const { lettre_acces_error } = await searchParams;
  const erreurLettre = ERREURS_LETTRE.find((e) => e === lettre_acces_error) ?? null;

  return (
    <EspaceGabarit candidature={candidature} scolarite={scolarite} rubrique="acces">
      <DossierAccesCard scolarite={scolarite} consultation={consultation} erreurLettre={erreurLettre} />
    </EspaceGabarit>
  );
}
