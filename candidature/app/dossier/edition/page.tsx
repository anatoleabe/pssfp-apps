import Link from 'next/link';
import { redirect } from 'next/navigation';
import { DossierEditionForm } from '@/components/DossierEditionForm';
import { getMyCandidature, getPays, getSpecialites } from '@/lib/api/client';
import { clearCandidatToken, getCandidatToken } from '@/lib/auth/session';
import { EDITABLE_FIELDS, type EditableField } from '@/lib/dossier/editableFields';
import { FALLBACK_PAYS, FALLBACK_SPECIALITES } from '@/lib/api/fallbacks';
import type { Pays, Specialite } from '@/lib/api/types';

export const metadata = {
  title: 'Éditer mon dossier',
};

interface EditionPageProps {
  searchParams: Promise<{ focus?: string }>;
}

export default async function DossierEditionPage({
  searchParams,
}: EditionPageProps): Promise<JSX.Element> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const { focus } = await searchParams;
  const focusField: EditableField | null =
    focus !== undefined && (EDITABLE_FIELDS as readonly string[]).includes(focus)
      ? (focus as EditableField)
      : null;

  const result = await getMyCandidature(token);
  if (!result.ok) {
    if (result.status === 401) {
      await clearCandidatToken();
      redirect('/login?reason=session_expired');
    }
    return (
      <div className="mx-auto max-w-4xl px-6 py-10 md:py-16">
        <h1 className="font-heading text-3xl font-bold text-[#6B2FA0]">Éditer mon dossier</h1>
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

  // Garde-fou : si le dossier est verrouillé, on redirige vers /dossier avec un
  // bandeau d'information (cf. spec PR H "Dossier verrouillé").
  if (candidature.statut !== 'postulant' || candidature.withdrawn_at !== null) {
    redirect('/dossier?reason=locked');
  }

  // Référentiels : avec fallbacks sur indisponibilité backend (cf. PR E ajout 4).
  const [paysResult, specialitesResult] = await Promise.all([getPays(), getSpecialites()]);
  const pays: Pays[] =
    paysResult.ok && Array.isArray(paysResult.data) && paysResult.data.length > 0
      ? paysResult.data
      : [...FALLBACK_PAYS];
  const specialites: Specialite[] =
    specialitesResult.ok && Array.isArray(specialitesResult.data) && specialitesResult.data.length > 0
      ? specialitesResult.data
      : [...FALLBACK_SPECIALITES];

  return (
    <div className="mx-auto max-w-4xl px-6 py-10 md:py-16">
      <nav aria-label="Fil d'Ariane" className="mb-6 text-sm text-[#666]">
        <Link href="/dossier" className="hover:text-[#6B2FA0]">
          Mon dossier
        </Link>
        <span aria-hidden="true"> / </span>
        <span className="text-[#333]">Édition</span>
      </nav>

      <header className="mb-6 flex flex-wrap items-baseline justify-between gap-3">
        <div>
          <h1 className="font-heading text-3xl font-bold text-[#6B2FA0]">Éditer mon dossier</h1>
          <p className="mt-1 text-sm text-[#666]">
            Vos modifications sont enregistrées automatiquement après 2 secondes d'inactivité.
          </p>
        </div>
        <Link
          href="/dossier"
          data-testid="edition-back"
          className="inline-flex h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm text-[#333] hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
        >
          ← Retour au dossier
        </Link>
      </header>

      <DossierEditionForm
        candidature={candidature}
        pays={pays}
        specialites={specialites}
        focusField={focusField}
      />
    </div>
  );
}
