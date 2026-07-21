import Link from 'next/link';
import { redirect } from 'next/navigation';
import { DocumentsUploader } from '@/components/DocumentsUploader';
import { getMyCandidature } from '@/lib/api/client';
import { getCandidatToken } from '@/lib/auth/session';

export const metadata = {
  title: 'Pièces justificatives — Mon dossier',
};

export default async function PiecesPage(): Promise<JSX.Element> {
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
        <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">Pièces justificatives</h1>
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
      <nav aria-label="Fil d'Ariane" className="mb-6 text-sm text-[#666]">
        <Link href="/dossier" className="hover:text-[#4A2E67]">
          Mon dossier
        </Link>
        <span aria-hidden="true"> / </span>
        <span className="text-[#333]">Pièces justificatives</span>
      </nav>

      <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">Pièces justificatives</h1>
      <p className="mt-2 text-sm text-[#666]">
        Diplôme, acte de naissance, relevés de notes, CV, lettre de motivation, attestation
        employeur — cf. communiqué officiel d&apos;appel à candidature.
      </p>

      <div className="mt-8 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <DocumentsUploader initialDocuments={candidature.documents ?? []} isLocked={isLocked} />
      </div>

      <div className="mt-6 flex justify-between text-sm">
        <Link href="/dossier" className="text-[#4A2E67] underline hover:text-[#5C3A7E]">
          ← Retour au dossier
        </Link>
      </div>
    </div>
  );
}
