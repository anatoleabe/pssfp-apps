import { redirect } from 'next/navigation';
import { AlertCircle, ArrowRight, Sparkles } from 'lucide-react';
import { DossierActionsCard } from '@/components/dossier/DossierActionsCard';
import { DossierCompleteness } from '@/components/dossier/DossierCompleteness';
import { DossierEtapesRestantes } from '@/components/dossier/DossierEtapesRestantes';
import { DossierFraisCard } from '@/components/dossier/DossierFraisCard';
import { DossierPhotoCard } from '@/components/dossier/DossierPhotoCard';
import { DossierPiecesCard } from '@/components/dossier/DossierPiecesCard';
import { DossierStatutCard } from '@/components/dossier/DossierStatutCard';
import { getMyCandidature } from '@/lib/api/client';
import { clearCandidatToken, getCandidatToken } from '@/lib/auth/session';
import { initDossierAction } from './actions';

export const metadata = {
  title: 'Mon dossier candidat',
};

interface DossierPageProps {
  searchParams: Promise<{ profile_pending?: string; reason?: string; init_error?: string }>;
}

function getInitials(prenom?: string | null, nom?: string | null): string {
  const p = (prenom ?? '').trim().charAt(0);
  const n = (nom ?? '').trim().charAt(0);
  return (p + n).toUpperCase() || '?';
}

export default async function DossierPage({ searchParams }: DossierPageProps): Promise<JSX.Element> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const { profile_pending, reason, init_error } = await searchParams;
  const result = await getMyCandidature(token);

  if (!result.ok) {
    if (result.status === 401) {
      await clearCandidatToken();
      redirect('/login?reason=session_expired');
    }
    if (result.status === 404 && result.code === 'campaign_closed') {
      return (
        <div className="mx-auto max-w-4xl px-6 py-12 md:py-16">
          <h1 className="pssfp-h1">Mon dossier</h1>
          <div className="mt-8 rounded-pssfp-card border border-amber-200 bg-amber-50/80 p-7 shadow-pssfp-soft backdrop-blur-2xs">
            <div className="flex items-start gap-4">
              <span
                aria-hidden="true"
                className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700"
              >
                <AlertCircle size={24} />
              </span>
              <div>
                <p className="font-heading text-lg font-bold text-amber-900">
                  Aucune campagne de candidature n&apos;est ouverte actuellement.
                </p>
                <p className="mt-2 text-sm leading-relaxed text-amber-900/90">
                  Votre compte est bien créé. Revenez dès l&apos;ouverture de la prochaine
                  campagne pour constituer votre dossier.
                </p>
              </div>
            </div>
          </div>
        </div>
      );
    }

    if (result.status === 404 && result.code === 'candidature_missing') {
      return (
        <div className="mx-auto max-w-4xl px-6 py-12 md:py-16">
          <h1 className="pssfp-h1">Mon dossier</h1>
          <div className="mt-8 rounded-pssfp-card border border-amber-200 bg-amber-50/80 p-7 shadow-pssfp-soft backdrop-blur-2xs">
            <div className="flex items-start gap-4">
              <span
                aria-hidden="true"
                className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700"
              >
                <AlertCircle size={24} />
              </span>
              <div>
                <p className="font-heading text-lg font-bold text-amber-900">
                  Votre dossier n&apos;a pas encore été initialisé.
                </p>
                <p className="mt-2 text-sm leading-relaxed text-amber-900/90">
                  Cela peut arriver si la connexion a été interrompue à l&apos;inscription. Votre
                  compte existe déjà — il suffit d&apos;initialiser votre dossier pour continuer.
                </p>
                {init_error === '1' && (
                  <p role="alert" className="mt-3 text-sm font-medium text-red-700">
                    Une erreur est survenue. Réessayez dans quelques instants.
                  </p>
                )}
                <form action={initDossierAction}>
                  <button
                    type="submit"
                    className="mt-5 inline-flex items-center gap-2 rounded-pssfp-button bg-[#4A2E67] px-5 py-2.5 text-sm font-semibold text-white shadow-pssfp-elevated transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#3A2452] hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
                  >
                    Initialiser mon dossier
                    <ArrowRight size={14} aria-hidden="true" />
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
      );
    }
    return (
      <div className="mx-auto max-w-4xl px-6 py-16">
        <h1 className="pssfp-h1">Mon dossier</h1>
        <div
          role="alert"
          className="mt-8 rounded-pssfp-card border border-red-200 bg-red-50/80 p-5 text-sm text-red-800 shadow-pssfp-soft"
        >
          Erreur de chargement du dossier : {result.message}. Réessayez dans quelques instants.
        </div>
      </div>
    );
  }

  const candidature = result.data;
  const initials = getInitials(candidature.prenom, candidature.nom);
  const fullName = `${candidature.prenom ?? ''} ${candidature.nom ?? ''}`.trim();

  return (
    <div className="relative isolate min-h-[60vh]">
      {/* Background décoratif */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-72 bg-[#FAF7F2]"
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -left-32 top-12 -z-10 h-72 w-72 rounded-full bg-[#4A2E67]/20 opacity-20 blur-3xl"
      />

      <div className="mx-auto max-w-5xl px-6 py-10 md:py-16">
        <header className="mb-10 flex flex-wrap items-center gap-5">
          {/* Avatar avec initiales + halo */}
          <div className="relative">
            <div
              aria-hidden="true"
              className="absolute -inset-1 -z-10 rounded-full bg-[#D4AF6A]/20 opacity-50 blur-xl"
            />
            <span
              aria-hidden="true"
              className="inline-flex h-16 w-16 items-center justify-center rounded-full bg-[#4A2E67] font-heading text-xl font-bold text-white shadow-pssfp-elevated ring-4 ring-white"
            >
              {initials}
            </span>
          </div>
          <div className="flex-1">
            <p className="pssfp-eyebrow inline-flex items-center gap-2">
              <Sparkles size={12} aria-hidden="true" />
              Espace candidat
            </p>
            <h1 className="mt-1 font-heading text-2xl font-bold text-[#1A1A1A] md:text-3xl">
              Bonjour <span className="text-[#4A2E67]">{candidature.prenom || 'candidat'}</span>
              <span aria-hidden="true">,</span>
            </h1>
            {fullName && (
              <p className="mt-1 text-sm text-[#666]">
                <span className="font-mono">{candidature.numero_dossier}</span>
                {' · '}
                <span>{fullName}</span>
              </p>
            )}
          </div>
        </header>

        {profile_pending === '1' && (
          <div
            role="status"
            className="mb-6 flex items-start gap-3 rounded-pssfp-card border border-amber-200 bg-amber-50/90 p-5 text-sm text-amber-900 shadow-pssfp-soft backdrop-blur-2xs"
          >
            <AlertCircle size={20} aria-hidden="true" className="mt-0.5 shrink-0 text-amber-700" />
            <div>
              <p className="font-semibold">Profil partiellement enregistré</p>
              <p className="mt-1 leading-relaxed">
                Votre compte a bien été créé, mais certaines informations de profil n&apos;ont pas
                pu être enregistrées. Vous pouvez compléter votre dossier ci-dessous.
              </p>
            </div>
          </div>
        )}

        {reason === 'locked' && (
          <div
            role="status"
            data-testid="dossier-locked-banner"
            className="mb-6 flex items-start gap-3 rounded-pssfp-card border border-amber-200 bg-amber-50/90 p-5 text-sm text-amber-900 shadow-pssfp-soft backdrop-blur-2xs"
          >
            <AlertCircle size={20} aria-hidden="true" className="mt-0.5 shrink-0 text-amber-700" />
            <div>
              <p className="font-semibold">Dossier verrouillé</p>
              <p className="mt-1 leading-relaxed">
                Votre dossier a déjà été soumis (ou retiré) et ne peut plus être modifié.
                Consultez votre suivi pour plus d&apos;informations.
              </p>
            </div>
          </div>
        )}

        <div className="grid gap-6">
          <DossierStatutCard candidature={candidature} />
          {candidature.statut !== 'postulant' && (
            <DossierEtapesRestantes candidature={candidature} />
          )}
          <div className="grid gap-6 md:grid-cols-2">
            <DossierCompleteness candidature={candidature} />
            <DossierPhotoCard candidature={candidature} />
          </div>
          <DossierPiecesCard candidature={candidature} />
          <DossierFraisCard candidature={candidature} />
          <DossierActionsCard candidature={candidature} />
        </div>
      </div>
    </div>
  );
}
