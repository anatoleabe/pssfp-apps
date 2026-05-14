import Link from 'next/link';
import { redirect } from 'next/navigation';
import { PhotoUploader } from '@/components/PhotoUploader';
import { getMyCandidature } from '@/lib/api/client';
import { clearCandidatToken, getCandidatToken } from '@/lib/auth/session';

export const metadata = {
  title: 'Photo identité — Mon dossier',
};

export default async function PhotoPage(): Promise<JSX.Element> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const result = await getMyCandidature(token);
  if (!result.ok) {
    if (result.status === 401) {
      await clearCandidatToken();
      redirect('/login?reason=session_expired');
    }
    return (
      <div className="mx-auto max-w-3xl px-6 py-10 md:py-16">
        <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">Photo identité</h1>
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
  const initialSignedUrl = candidature.photo_url ?? null;
  const hasPhoto = candidature.has_photo === true;

  return (
    <div className="mx-auto max-w-3xl px-6 py-10 md:py-16">
      <nav aria-label="Fil d'Ariane" className="mb-6 text-sm text-[#666]">
        <Link href="/dossier" className="hover:text-[#4A2E67]">
          Mon dossier
        </Link>
        <span aria-hidden="true"> / </span>
        <span className="text-[#333]">Photo identité</span>
      </nav>

      <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">Photo identité</h1>
      <p className="mt-2 text-sm text-[#666]">
        Cette photo apparaîtra sur votre récépissé et sera vérifiée au dépôt physique de votre
        dossier. Choisissez une photo récente, bien éclairée, fond neutre.
      </p>

      <div className="mt-8 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <PhotoUploader
          initialHasPhoto={hasPhoto}
          initialSignedUrl={initialSignedUrl}
          isLocked={isLocked}
        />
      </div>

      <div className="mt-6 flex justify-between text-sm">
        <Link href="/dossier" className="text-[#4A2E67] underline hover:text-[#5C3A7E]">
          ← Retour au dossier
        </Link>
      </div>
    </div>
  );
}
