import { getTranslations } from 'next-intl/server';
import { Link } from '@/navigation';
import { redirect } from '@/navigation';
import { DocumentsUploader } from '@/components/DocumentsUploader';
import { getMyCandidature } from '@/lib/api/client';
import { getCandidatToken } from '@/lib/auth/session';

export const metadata = {
  // Page d'authentification ou espace candidat : hors index (audit A-32).
  robots: { index: false, follow: false },
  title: 'Pièces justificatives — Mon dossier',
};

export default async function PiecesPage(): Promise<JSX.Element> {
  const tpp = await getTranslations('dossier.piecesPage');
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const result = await getMyCandidature(token);
  if (!result.ok) {
    if (result.status === 401) {
      redirect('/auth/session-expired');
    }
    return (
      <div className="mx-auto max-w-3xl px-6 py-10 md:py-16">
        <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">{tpp('title')}</h1>
        <p
          role="alert"
          className="mt-6 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-700"
        >
          Erreur de chargement : {result.message}. Réessayez dans quelques instants.
        </p>
      </div>
    );
  }

  const candidature = result.data;
  const isLocked = candidature.statut !== 'postulant';

  return (
    <div className="mx-auto max-w-3xl px-6 py-10 md:py-16">
      <nav aria-label={tpp('breadcrumbAria')} className="mb-6 text-sm text-[#666]">
        <Link href="/dossier" className="hover:text-[#4A2E67]">
          {tpp('breadcrumbRoot')}
        </Link>
        <span aria-hidden="true"> / </span>
        <span className="text-[#333]">{tpp('title')}</span>
      </nav>

      <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">{tpp('title')}</h1>
      <p className="mt-2 text-sm text-[#666]">
        Copie certifiée conforme du diplôme ou attestation de réussite, photocopie légalisée de
        l&apos;acte de naissance, relevés de note L1 à L3 signés, CV détaillé, lettre de motivation
        adressée au Président du Comité de Pilotage du PSSFP, attestation de présence effective au
        poste ou autorisation de l&apos;employeur le cas échéant — cf. communiqué officiel d&apos;appel
        à candidatures.
      </p>

      <div className="mt-8 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <DocumentsUploader initialDocuments={candidature.documents ?? []} isLocked={isLocked} />
      </div>

      <div className="mt-6 flex justify-between text-sm">
        <Link href="/dossier" className="text-[#4A2E67] underline hover:text-[#5C3A7E]">
          {tpp('back')}
        </Link>
      </div>
    </div>
  );
}
