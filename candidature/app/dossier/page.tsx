import { redirect } from 'next/navigation';
import { DossierActionsCard } from '@/components/dossier/DossierActionsCard';
import { DossierCompleteness } from '@/components/dossier/DossierCompleteness';
import { DossierEtapesRestantes } from '@/components/dossier/DossierEtapesRestantes';
import { DossierFraisCard } from '@/components/dossier/DossierFraisCard';
import { DossierPhotoCard } from '@/components/dossier/DossierPhotoCard';
import { DossierStatutCard } from '@/components/dossier/DossierStatutCard';
import { getMyCandidature } from '@/lib/api/client';
import { clearCandidatToken, getCandidatToken } from '@/lib/auth/session';

export const metadata = {
  title: 'Mon dossier candidat',
};

interface DossierPageProps {
  searchParams: Promise<{ profile_pending?: string; reason?: string }>;
}

export default async function DossierPage({ searchParams }: DossierPageProps): Promise<JSX.Element> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const { profile_pending, reason } = await searchParams;
  const result = await getMyCandidature(token);

  if (!result.ok) {
    if (result.status === 401) {
      await clearCandidatToken();
      redirect('/login?reason=session_expired');
    }
    if (result.status === 404) {
      // Pas encore de Candidature liée — peut arriver après partial failure
      // (cf. ajout 4 PR E). On invite à compléter via le wizard.
      return (
        <div className="mx-auto max-w-4xl px-6 py-10 md:py-16">
          <h1 className="font-heading text-3xl font-bold text-[#6B2FA0]">Mon dossier</h1>
          <div className="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-6 text-sm text-amber-900">
            <p className="font-semibold">Votre dossier n'a pas encore été initialisé.</p>
            <p className="mt-2">
              Cela peut arriver si la connexion a été interrompue à l'inscription. Reprenez
              le formulaire pour finaliser la création de votre profil.
            </p>
            <a
              href="/inscription"
              className="mt-4 inline-flex items-center rounded-md bg-[#6B2FA0] px-4 py-2 font-medium text-white hover:bg-[#9B59B6]"
            >
              Reprendre l'inscription
            </a>
          </div>
        </div>
      );
    }
    return (
      <div className="mx-auto max-w-4xl px-6 py-16">
        <h1 className="font-heading text-3xl font-bold text-[#6B2FA0]">Mon dossier</h1>
        <div role="alert" className="mt-6 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-700">
          Erreur de chargement du dossier : {result.message}. Réessayez dans quelques instants.
        </div>
      </div>
    );
  }

  const candidature = result.data;

  return (
    <div className="mx-auto max-w-4xl px-6 py-10 md:py-16">
      <header className="mb-8 flex flex-wrap items-baseline justify-between gap-3">
        <div>
          <h1 className="font-heading text-3xl font-bold text-[#6B2FA0]">Mon dossier candidat</h1>
          <p className="mt-1 text-sm text-[#666]">
            Bienvenue {candidature.prenom ?? ''} {candidature.nom ?? ''}.
          </p>
        </div>
      </header>

      {profile_pending === '1' && (
        <div role="status" className="mb-6 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
          Votre compte a bien été créé, mais certaines informations de profil n'ont pas
          pu être enregistrées. Vous pouvez compléter votre dossier ci-dessous.
        </div>
      )}

      {reason === 'locked' && (
        <div
          role="status"
          data-testid="dossier-locked-banner"
          className="mb-6 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
        >
          Votre dossier est verrouillé : il a déjà été soumis (ou retiré) et ne peut plus être
          modifié. Consultez votre suivi pour plus d'informations.
        </div>
      )}

      <div className="grid gap-6">
        <DossierStatutCard candidature={candidature} />
        {candidature.statut !== 'postulant' && (
          <DossierEtapesRestantes candidature={candidature} />
        )}
        <DossierCompleteness candidature={candidature} />
        <DossierFraisCard candidature={candidature} />
        <DossierPhotoCard candidature={candidature} />
        <DossierActionsCard candidature={candidature} />
      </div>
    </div>
  );
}
