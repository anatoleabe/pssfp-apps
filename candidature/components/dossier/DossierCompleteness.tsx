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
  'telephone1', 'email', 'specialite', 'type_etude', 'premiere_langue',
  'diplome_obtenu', 'institut', 'specialite_diplome', 'annee_diplome',
  'statut_actuel', 'fonction_actuelle', 'employeur', 'moyen_connaissance',
  'moyen_connaissance_detail', 'engagement_nom',
]);

export function DossierCompleteness({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const t = useTranslations('dossier');
  const tErr = useTranslations('errors');
  const result = checkSubmittable(candidature);
  const [serverError, setServerError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [reviewConfirmed, setReviewConfirmed] = useState(false);
  const [reviewError, setReviewError] = useState<string | null>(null);
  const confirmButtonRef = useRef<HTMLButtonElement | null>(null);
  const reviewCheckboxRef = useRef<HTMLInputElement | null>(null);
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
    if (confirmOpen) reviewCheckboxRef.current?.focus();
  }, [confirmOpen]);

  const openConfirmation = (): void => {
    setReviewConfirmed(false);
    setReviewError(null);
    setConfirmOpen(true);
  };

  const confirmSubmission = (): void => {
    if (!reviewConfirmed) {
      setReviewError('Confirmez que vous avez relu et vérifié votre dossier.');
      reviewCheckboxRef.current?.focus();
      return;
    }
    setConfirmOpen(false);
    handleSubmit();
  };

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
        onClick={openConfirmation}
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
            <section className="mt-5 rounded-lg border border-[#E4DCEE] bg-[#FAF7FF] p-4" aria-labelledby="submit-review-heading">
              <div className="flex items-center justify-between gap-3">
                <h4 id="submit-review-heading" className="font-heading font-bold text-[#4A2E67]">
                  Informations essentielles à relire
                </h4>
                <Link href="/dossier/edition" className="text-sm font-semibold text-[#4A2E67] underline">
                  Modifier
                </Link>
              </div>
              <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                <ReviewItem label="Candidat" value={`${candidature.prenom ?? ''} ${candidature.nom ?? ''}`.trim()} />
                <ReviewItem label="Adresse e-mail" value={candidature.email ?? ''} />
                <ReviewItem label="Téléphone" value={`${candidature.indicatif1 ?? ''} ${candidature.telephone1 ?? ''}`.trim()} />
                <ReviewItem label="Spécialité" value={candidature.specialite ?? ''} />
                <ReviewItem label="Diplôme" value={candidature.diplome_obtenu ?? ''} />
                <ReviewItem label="Établissement" value={candidature.institut ?? ''} />
                <ReviewItem label="Situation actuelle" value={candidature.statut_actuel ?? ''} />
                <ReviewItem label="Employeur" value={candidature.employeur ?? 'Sans objet'} />
              </dl>
            </section>
            <div className="mt-5" data-field-error={Boolean(reviewError)}>
              <label className="flex cursor-pointer items-start gap-3 text-sm font-semibold leading-relaxed text-[#333333]">
                <input
                  ref={reviewCheckboxRef}
                  type="checkbox"
                  data-testid="dossier-review-confirmation"
                  checked={reviewConfirmed}
                  onChange={(event) => {
                    setReviewConfirmed(event.target.checked);
                    setReviewError(null);
                  }}
                  aria-invalid={Boolean(reviewError)}
                  aria-describedby={reviewError ? 'dossier-review-error' : undefined}
                  className="mt-0.5 h-5 w-5 shrink-0 rounded border-gray-300 text-[#4A2E67] focus:ring-[#4A2E67]"
                />
                J’ai relu les informations de mon dossier et je confirme qu’elles sont exactes et complètes.
              </label>
              {reviewError && (
                <p id="dossier-review-error" role="alert" className="mt-2 text-sm font-semibold text-red-700">
                  {reviewError}
                </p>
              )}
            </div>
            <div className="mt-6 flex flex-wrap justify-end gap-3">
              <button type="button" onClick={() => setConfirmOpen(false)} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-[#333333] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67]">Annuler</button>
              <button ref={confirmButtonRef} type="button" data-testid="dossier-submit-confirm" onClick={confirmSubmission} className="rounded-md bg-[#4A2E67] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2">Certifier et soumettre ma candidature</button>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}

function ReviewItem({ label, value }: { label: string; value: string }): JSX.Element {
  return (
    <div>
      <dt className="text-xs font-semibold uppercase tracking-wide text-[#777]">{label}</dt>
      <dd className="mt-0.5 break-words font-medium text-[#292929]">{value || 'Non renseigné'}</dd>
    </div>
  );
}
