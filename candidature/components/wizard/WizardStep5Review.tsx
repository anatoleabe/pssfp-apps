'use client';

import { CheckCircle2, Pencil, ShieldCheck } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { Pays } from '@/lib/api/types';
import { formatDateFr } from '@/lib/format/date';
import {
  DIPLOME_REQUIS_OPTIONS,
  DOMAINE_DIPLOME_OPTIONS,
  STATUT_ACTUEL_OPTIONS,
  needsSpecialiteDiplomeRequis,
} from '@/lib/dossier/options';
import type { WizardData } from './types';

interface WizardStep5ReviewProps {
  data: WizardData;
  pays: Pays[];
  confirmed: boolean;
  confirmationError?: string;
  onConfirmedChange: (confirmed: boolean) => void;
  onEditStep: (step: 1 | 2 | 3 | 4) => void;
}

export function WizardStep5Review({
  data,
  pays,
  confirmed,
  confirmationError,
  onConfirmedChange,
  onEditStep,
}: WizardStep5ReviewProps): JSX.Element {
  const t = useTranslations('wizard.step5');
  const to = useTranslations('options');
  const editLabel = t('edit');
  const countryName = (code: string): string =>
    pays.find((country) => country.code_iso === code)?.nom ?? code;
  const statusLabel =
    STATUT_ACTUEL_OPTIONS.find((option) => option.value === data.statut_actuel)?.label ??
    data.statut_actuel;

  return (
    <div className="space-y-6" data-testid="wizard-step-5">
      <header>
        <p className="text-sm font-semibold uppercase tracking-wider text-[#8A641D]">
          {t('eyebrow')}
        </p>
        <h2 className="mt-1 font-heading text-2xl font-bold text-[#4A2E67]">
          {t('title')}
        </h2>
        <p className="mt-2 text-sm leading-relaxed text-[#595959]">
          Relisez attentivement toutes les informations ci-dessous. Utilisez « Modifier » pour
          corriger une rubrique avant de créer votre compte candidat.
        </p>
      </header>

      <ReviewSection title={t('sectionFormation')} step={1} onEditStep={onEditStep} editLabel={editLabel}>
        <ReviewRow label={t('fields.specialisation')} value={data.specialite} />
        <ReviewRow
          label={t('fields.studyMode')}
          value={data.type_etude === 'presentiel' ? 'Présentiel' : 'Distanciel'}
        />
        <ReviewRow
          label={t('fields.firstLanguage')}
          value={data.premiere_langue === 'fr' ? to('langueFr') : to('langueEn')}
        />
      </ReviewSection>

      <ReviewSection title={t('sectionIdentite')} step={1} onEditStep={onEditStep} editLabel={editLabel}>
        <ReviewRow
          label={t('fields.fullName')}
          value={`${data.civilite} ${data.prenom} ${data.nom}${data.epouse ? ` — ${t('usageName')} : ${data.epouse}` : ''}`}
        />
        <ReviewRow label={t('fields.birthDate')} value={formatDateFr(data.date_naissance)} />
        <ReviewRow label={t('fields.birthPlace')} value={data.lieu_naissance} />
        <ReviewRow
          label={t('fields.gender')}
          value={data.genre === 'M' ? to('genreM') : data.genre === 'F' ? to('genreF') : to('genreAutre')}
        />
        <ReviewRow label={t('fields.maritalStatus')} value={data.statut_matrimonial} />
        <ReviewRow label={t('fields.nationality')} value={countryName(data.nationalite)} />
      </ReviewSection>

      <ReviewSection title={t('sectionCoordonnees')} step={2} onEditStep={onEditStep} editLabel={editLabel}>
        <ReviewRow label={t('fields.email')} value={data.email} emphasized />
        <ReviewRow label={t('fields.mainPhone')} value={data.phone_e164} />
        {(data.indicatif2 || data.telephone2) && (
          <ReviewRow
            label={t('fields.secondPhone')}
            value={`${data.indicatif2} ${data.telephone2}`.trim()}
          />
        )}
        <ReviewRow label={t('fields.countryOrigin')} value={countryName(data.pays_origine)} />
        <ReviewRow label={t('fields.countryResidence')} value={countryName(data.pays_residence)} />
        {data.region && <ReviewRow label={t('fields.region')} value={data.region} />}
        {data.departement && <ReviewRow label={t('fields.division')} value={data.departement} />}
        <ReviewRow
          label={t('fields.homeAddress')}
          value={`${data.adresse}, ${data.ville_residence}`}
        />
      </ReviewSection>

      <ReviewSection title={t('sectionParcours')} step={3} onEditStep={onEditStep} editLabel={editLabel}>
        <ReviewRow label={t('fields.degree')} value={data.diplome_obtenu} />
        <ReviewRow label={t('fields.institution')} value={data.institut} />
        <ReviewRow label={t('fields.degreeField')} value={data.specialite_diplome} />
        <ReviewRow label={t('fields.gradYear')} value={String(data.annee_diplome)} />
        <ReviewRow
          label={t('fields.requiredDegree')}
          value={DIPLOME_REQUIS_OPTIONS.find((o) => o.value === data.diplome_requis)?.label ?? ''}
        />
        <ReviewRow
          label={t('fields.requiredDegreeYear')}
          value={data.annee_diplome_requis === '' ? '' : String(data.annee_diplome_requis)}
        />
        <ReviewRow
          label={t('fields.requiredDegreeDomain')}
          value={
            DOMAINE_DIPLOME_OPTIONS.find((o) => o.value === data.domaine_diplome_requis)?.label ?? ''
          }
        />
        {needsSpecialiteDiplomeRequis(data.domaine_diplome_requis) && (
          <ReviewRow
            label={t('fields.requiredDegreeField')}
            value={data.specialite_diplome_requis}
          />
        )}
        <ReviewRow
          label={t('fields.requiredDegreeInstitution')}
          value={data.institut_diplome_requis}
        />
        <ReviewRow
          label={t('fields.otherDegrees')}
          value={
            data.autres_diplomes.length === 0
              ? t('fields.none')
              : data.autres_diplomes
                  .map((d) => `${d.intitule} — ${d.etablissement} (${d.annee})`)
                  .join(' · ')
          }
        />
        <ReviewRow
          label={t('fields.proTraining')}
          value={
            data.formations_professionnelles.length === 0
              ? t('fields.none')
              : data.formations_professionnelles
                  .map((f) => `${f.qualification} — ${f.centre} (${f.annee})`)
                  .join(' · ')
          }
        />
        <ReviewRow label={t('fields.currentSituation')} value={statusLabel} />
        {data.fonction_actuelle && <ReviewRow label={t('fields.role')} value={data.fonction_actuelle} />}
        {data.employeur && <ReviewRow label={t('fields.employer')} value={data.employeur} />}
        {data.adresse_employeur && (
          <ReviewRow label={t('fields.employerAddress')} value={data.adresse_employeur} />
        )}
        {data.tel_employeur && (
          <ReviewRow label={t('fields.employerPhone')} value={data.tel_employeur} />
        )}
        <ReviewRow label={t('fields.howHeard')} value={data.moyen_connaissance} />
        {data.moyen_connaissance_detail && (
          <ReviewRow label={t('fields.details')} value={data.moyen_connaissance_detail} />
        )}
      </ReviewSection>

      <ReviewSection title={t('sectionSecurite')} step={4} onEditStep={onEditStep} editLabel={editLabel}>
        <ReviewRow label={t('fields.pin')} value="Défini et masqué pour votre sécurité" />
        <ReviewRow label={t('fields.honourDeclaration')} value="Acceptée" />
        <ReviewRow label={t('fields.terms')} value="Acceptées" />
      </ReviewSection>

      <section
        className="rounded-lg border-2 border-[#D4AF6A] bg-[#FFFBEA] p-5"
        data-field-error={Boolean(confirmationError)}
      >
        <div className="flex items-start gap-3">
          <CheckCircle2 className="mt-0.5 shrink-0 text-[#8A641D]" size={22} aria-hidden="true" />
          <div>
            <label className="flex cursor-pointer items-start gap-3 text-sm font-semibold leading-relaxed text-[#332515]">
              <input
                type="checkbox"
                data-testid="review-confirmation"
                checked={confirmed}
                onChange={(event) => onConfirmedChange(event.target.checked)}
                aria-invalid={Boolean(confirmationError)}
                aria-describedby={confirmationError ? 'review-confirmation-error' : 'review-confirmation-help'}
                className="mt-0.5 h-5 w-5 shrink-0 rounded border-amber-400 text-[#4A2E67] focus:ring-[#4A2E67]"
              />
              Je confirme avoir relu l’ensemble des informations ci-dessus et certifie qu’elles
              sont exactes et complètes.
            </label>
            <p id="review-confirmation-help" className="mt-2 text-xs leading-relaxed text-[#6B4E1E]">
              Après la création du compte, votre dossier restera modifiable depuis votre espace
              candidat jusqu’à sa soumission définitive.
            </p>
            {confirmationError && (
              <p id="review-confirmation-error" role="alert" className="mt-2 text-sm font-semibold text-red-700">
                {confirmationError}
              </p>
            )}
          </div>
        </div>
      </section>

      <div className="flex items-center gap-2 rounded-md bg-[#F4EFFA] p-3 text-xs text-[#4A2E67]">
        <ShieldCheck size={18} aria-hidden="true" />
        {t('pinNotice')}
      </div>
    </div>
  );
}

function ReviewSection({
  title,
  step,
  onEditStep,
  editLabel,
  children,
}: {
  title: string;
  step: 1 | 2 | 3 | 4;
  onEditStep: (step: 1 | 2 | 3 | 4) => void;
  editLabel: string;
  children: React.ReactNode;
}): JSX.Element {
  return (
    <section className="overflow-hidden rounded-lg border border-[#E4DCEE] bg-white">
      <div className="flex items-center justify-between gap-4 bg-[#FAF7FF] px-4 py-3 sm:px-5">
        <h3 className="font-heading text-lg font-bold text-[#4A2E67]">{title}</h3>
        <button
          type="button"
          onClick={() => onEditStep(step)}
          className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-semibold text-[#4A2E67] underline hover:bg-[#F4EFFA] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67]"
        >
          <Pencil size={14} aria-hidden="true" />
          {editLabel}
        </button>
      </div>
      <dl className="grid gap-x-8 px-4 py-2 sm:grid-cols-2 sm:px-5">{children}</dl>
    </section>
  );
}

function ReviewRow({
  label,
  value,
  emphasized = false,
}: {
  label: string;
  value: string;
  emphasized?: boolean;
}): JSX.Element {
  return (
    <div className="border-b border-gray-100 py-3 last:border-0">
      <dt className="text-xs font-semibold uppercase tracking-wide text-[#777]">{label}</dt>
      <dd className={`mt-1 break-words text-sm ${emphasized ? 'font-bold text-[#4A2E67]' : 'text-[#292929]'}`}>
        {value || 'Non renseigné'}
      </dd>
    </div>
  );
}
