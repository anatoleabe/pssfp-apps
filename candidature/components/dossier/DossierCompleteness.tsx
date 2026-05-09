'use client';

import { useState, useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { submitDossierAction } from '@/app/dossier/actions';
import type { MyCandidature } from '@/lib/api/client';
import { checkSubmittable } from '@/lib/validation/submittable';

const FIELD_LABELS_FR: Record<string, string> = {
  civilite: 'Civilité',
  nom: 'Nom',
  prenom: 'Prénom',
  date_naissance: 'Date de naissance',
  lieu_naissance: 'Lieu de naissance',
  genre: 'Genre',
  statut_matrimonial: 'Situation matrimoniale',
  nationalite: 'Nationalité',
  pays_origine: "Pays d'origine",
  pays_residence: 'Pays de résidence',
  region: 'Région (Cameroun)',
  departement: 'Département (Cameroun)',
  adresse: 'Adresse',
  ville_residence: 'Ville de résidence',
  indicatif1: 'Indicatif téléphone',
  telephone1: 'Numéro de téléphone',
  specialite: 'Spécialité',
  type_etude: "Type d'études",
  premiere_langue: 'Première langue',
  diplome_obtenu: 'Diplôme obtenu',
  institut: 'Établissement',
  specialite_diplome: 'Spécialité du diplôme',
  annee_diplome: "Année d'obtention du diplôme",
  statut_actuel: 'Situation actuelle',
  engagement_nom: 'Signature numérique',
};

export function DossierCompleteness({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const result = checkSubmittable(candidature);
  const [serverError, setServerError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();
  const router = useRouter();

  const isAlreadySubmitted = candidature.statut !== 'postulant';
  const canSubmit = result.ok && !isAlreadySubmitted && candidature.withdrawn_at === null;

  const handleSubmit = (): void => {
    setServerError(null);
    startTransition(async () => {
      const r = await submitDossierAction();
      if (r.ok) {
        router.refresh();
        return;
      }
      setServerError(r.message ?? 'Erreur de soumission.');
    });
  };

  if (isAlreadySubmitted) {
    return (
      <section
        aria-labelledby="completeness-heading"
        className="rounded-lg border border-emerald-200 bg-emerald-50 p-6"
      >
        <h2 id="completeness-heading" className="font-heading text-lg font-bold text-emerald-800">
          Candidature soumise
        </h2>
        <p className="mt-2 text-sm text-emerald-900">
          Votre dossier est désormais entre les mains du comité d'admission. Vous serez
          notifié(e) par email à la décision finale.
        </p>
      </section>
    );
  }

  return (
    <section
      aria-labelledby="completeness-heading"
      className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
    >
      <h2 id="completeness-heading" className="font-heading text-lg font-bold text-[#6B2FA0]">
        Avant de soumettre
      </h2>

      {result.missing.length === 0 && Object.keys(result.errors).length === 0 ? (
        <p className="mt-3 text-sm text-emerald-700">
          ✅ Votre profil est complet. Vous pouvez soumettre votre candidature.
        </p>
      ) : (
        <>
          <p className="mt-3 text-sm text-[#666]">
            Les éléments suivants restent à compléter (édition disponible prochainement
            depuis ce tableau de bord) :
          </p>
          <ul className="mt-3 space-y-1 text-sm text-amber-800" data-testid="dossier-missing-fields">
            {result.missing.map((field) => (
              <li key={field} className="flex items-center gap-2">
                <span aria-hidden>•</span>
                <span>{FIELD_LABELS_FR[field] ?? field}</span>
              </li>
            ))}
            {Object.entries(result.errors)
              .filter(([k]) => !result.missing.includes(k))
              .map(([k, msg]) => (
                <li key={k} className="flex items-center gap-2">
                  <span aria-hidden>⚠</span>
                  <span>{msg}</span>
                </li>
              ))}
          </ul>
        </>
      )}

      {serverError && (
        <div role="alert" className="mt-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
          {serverError}
        </div>
      )}

      <button
        type="button"
        data-testid="dossier-submit"
        disabled={!canSubmit || pending}
        onClick={handleSubmit}
        className="mt-5 inline-flex h-11 items-center rounded-md bg-[#6B2FA0] px-5 text-sm font-medium text-white hover:bg-[#9B59B6] disabled:cursor-not-allowed disabled:bg-gray-300"
      >
        {pending ? 'Soumission…' : 'Soumettre ma candidature'}
      </button>
    </section>
  );
}
