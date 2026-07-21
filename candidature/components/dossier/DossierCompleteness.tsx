'use client';

import Link from 'next/link';
import { useEffect, useRef, useState, useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { submitDossierAction } from '@/app/dossier/actions';
import type { MyCandidature } from '@/lib/api/client';
import { checkSubmittable } from '@/lib/validation/submittable';

/** Champs profil connus — mappés sur dossier.fields.* (fallback : code brut). */
const KNOWN_FIELDS = new Set([
  'civilite', 'nom', 'prenom', 'date_naissance', 'lieu_naissance', 'genre',
  'statut_matrimonial', 'nationalite', 'pays_origine', 'pays_residence',
  'region', 'departement', 'adresse', 'ville_residence', 'indicatif1',
  'telephone1', 'specialite', 'type_etude', 'premiere_langue',
  'diplome_obtenu', 'institut', 'specialite_diplome', 'annee_diplome',
  'statut_actuel', 'engagement_nom',
]);

export function DossierCompleteness({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const t = useTranslations('dossier');
  const tErr = useTranslations('errors');
  const result = checkSubmittable(candidature);
  const [serverError, setServerError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const confirmButtonRef = useRef<HTMLButtonElement | null>(null);
  const router = useRouter();

  const fieldLabel = (field: string): string =>
    KNOWN_FIELDS.has(field) ? t(`fields.${field}`) : field;

  // Clé d'idempotence stable sur toute la durée de vie du composant : un retry
  // réseau de la même intention de soumission réutilise la même clé, ce qui
  // permet au backend (cache 5 min sur X-Idempotency-Key) de ne pas régénérer
  // le récépissé ni double-soumettre. Généré paresseusement au 1er submit.
  const idempotencyKeyRef = useRef<string | null>(null);

  const isAlreadySubmitted = candidature.statut !== 'postulant';
  const canSubmit = result.ok && candidature.has_photo && !isAlreadySubmitted && candidature.withdrawn_at === null;
  const hasRecommendedDocuments = candidature.documents.length > 0;

  useEffect(() => {
    if (confirmOpen) confirmButtonRef.current?.focus();
  }, [confirmOpen]);

  const handleSubmit = (): void => {
    setServerError(null);
    if (idempotencyKeyRef.current === null) {
      idempotencyKeyRef.current = crypto.randomUUID();
    }
    const idempotencyKey = idempotencyKeyRef.current;
    startTransition(async () => {
      const r = await submitDossierAction(idempotencyKey);
      if (r.ok) {
        router.refresh();
        return;
      }
      // Priorité au code d'erreur structuré (traduisible), message serveur en fallback.
      const kindMap: Record<string, string> = {
        incomplete: tErr('incomplete'),
        already_submitted: tErr('alreadySubmitted'),
        unauthenticated: tErr('unauthenticated'),
        network: tErr('network'),
      };
      setServerError((r.errorKind && kindMap[r.errorKind]) ?? r.message ?? tErr('generic'));
    });
  };

  if (isAlreadySubmitted) {
    return (
      <section
        aria-labelledby="completeness-heading"
        className="rounded-lg border border-emerald-200 bg-emerald-50 p-6"
      >
        <h2 id="completeness-heading" className="font-heading text-lg font-bold text-emerald-800">
          {t('completeness.submittedTitle')}
        </h2>
        <p className="mt-2 text-sm text-emerald-900">{t('completeness.submittedBody')}</p>
      </section>
    );
  }

  return (
    <section
      aria-labelledby="completeness-heading"
      className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
    >
      <h2 id="completeness-heading" className="font-heading text-lg font-bold text-[#4A2E67]">
        {t('completeness.title')}
      </h2>

      <ul className="mt-4 space-y-2 text-sm" aria-label="Checklist avant soumission">
        <li className={candidature.has_photo ? 'text-emerald-800' : 'text-amber-900'}>
          <span aria-hidden="true">{candidature.has_photo ? '☑' : '☐'} </span>
          Photo d&apos;identité — obligatoire
        </li>
        <li className={hasRecommendedDocuments ? 'text-emerald-800' : 'text-[#595959]'}>
          <span aria-hidden="true">{hasRecommendedDocuments ? '☑' : '☐'} </span>
          Pièces justificatives — recommandées
        </li>
      </ul>

      {result.missing.length === 0 && Object.keys(result.errors).length === 0 ? (
        <p className="mt-3 text-sm text-emerald-700">
          <span aria-hidden="true">✅ </span>
          {t('completeness.completeNotice')}
        </p>
      ) : (
        <>
          <p className="mt-3 text-sm text-[#666]">{t('completeness.incompleteIntro')}</p>
          <ul className="mt-3 space-y-1 text-sm text-amber-800" data-testid="dossier-missing-fields">
            {result.missing.map((field) => (
              <li key={field} className="flex items-center gap-2">
                <span aria-hidden>•</span>
                <Link
                  href={`/dossier/edition?focus=${encodeURIComponent(field)}`}
                  className="underline hover:text-[#4A2E67]"
                >
                  {fieldLabel(field)}
                </Link>
              </li>
            ))}
            {Object.entries(result.errors)
              .filter(([k]) => !result.missing.includes(k))
              .map(([k, msg]) => (
                <li key={k} className="flex items-center gap-2">
                  <span aria-hidden>⚠</span>
                  <Link
                    href={`/dossier/edition?focus=${encodeURIComponent(k)}`}
                    className="underline hover:text-amber-900"
                  >
                    {msg}
                  </Link>
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
        onClick={() => setConfirmOpen(true)}
        className="mt-5 inline-flex h-11 items-center rounded-md bg-[#4A2E67] px-5 text-sm font-medium text-white hover:bg-[#5C3A7E] disabled:cursor-not-allowed disabled:bg-gray-300"
      >
        {pending ? t('completeness.submitting') : t('completeness.submit')}
      </button>
      {!candidature.has_photo && (
        <p className="mt-2 text-xs text-amber-900" role="status">
          Ajoutez votre photo d&apos;identité pour activer la soumission.
        </p>
      )}

      {confirmOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div role="alertdialog" aria-modal="true" aria-labelledby="submit-confirm-title" aria-describedby="submit-confirm-description" className="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
            <h3 id="submit-confirm-title" className="font-heading text-2xl font-bold text-[#4A2E67]">Confirmer la soumission définitive</h3>
            <p id="submit-confirm-description" className="mt-3 text-sm leading-relaxed text-[#333333]">
              Après confirmation, votre photo et vos pièces seront verrouillées. Vous ne pourrez plus les modifier ni revenir sur les informations certifiées.
            </p>
            <div className="mt-6 flex flex-wrap justify-end gap-3">
              <button type="button" onClick={() => setConfirmOpen(false)} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-[#333333] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67]">Annuler</button>
              <button ref={confirmButtonRef} type="button" data-testid="dossier-submit-confirm" onClick={() => { setConfirmOpen(false); handleSubmit(); }} className="rounded-md bg-[#4A2E67] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2">Certifier et soumettre ma candidature</button>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}
