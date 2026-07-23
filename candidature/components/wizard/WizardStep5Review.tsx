'use client';

import { CheckCircle2, Pencil, ShieldCheck } from 'lucide-react';
import type { Pays } from '@/lib/api/types';
import { formatDateFr } from '@/lib/format/date';
import { STATUT_ACTUEL_OPTIONS } from '@/lib/dossier/options';
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
  const countryName = (code: string): string =>
    pays.find((country) => country.code_iso === code)?.nom ?? code;
  const statusLabel =
    STATUT_ACTUEL_OPTIONS.find((option) => option.value === data.statut_actuel)?.label ??
    data.statut_actuel;

  return (
    <div className="space-y-6" data-testid="wizard-step-5">
      <header>
        <p className="text-sm font-semibold uppercase tracking-wider text-[#8A641D]">
          Dernière étape
        </p>
        <h2 className="mt-1 font-heading text-2xl font-bold text-[#4A2E67]">
          Vérification et confirmation
        </h2>
        <p className="mt-2 text-sm leading-relaxed text-[#595959]">
          Relisez attentivement toutes les informations ci-dessous. Utilisez « Modifier » pour
          corriger une rubrique avant de créer votre compte candidat.
        </p>
      </header>

      <ReviewSection title="Formation demandée" step={1} onEditStep={onEditStep}>
        <ReviewRow label="Spécialité" value={data.specialite} />
        <ReviewRow
          label="Modalité"
          value={data.type_etude === 'presentiel' ? 'Présentiel' : 'Distanciel'}
        />
        <ReviewRow
          label="Première langue"
          value={data.premiere_langue === 'fr' ? 'Français' : 'Anglais'}
        />
      </ReviewSection>

      <ReviewSection title="Identité" step={1} onEditStep={onEditStep}>
        <ReviewRow
          label="Nom complet"
          value={`${data.civilite} ${data.prenom} ${data.nom}${data.epouse ? ` — nom d’usage : ${data.epouse}` : ''}`}
        />
        <ReviewRow label="Date de naissance" value={formatDateFr(data.date_naissance)} />
        <ReviewRow label="Lieu de naissance" value={data.lieu_naissance} />
        <ReviewRow
          label="Genre"
          value={data.genre === 'M' ? 'Masculin' : data.genre === 'F' ? 'Féminin' : 'Autre'}
        />
        <ReviewRow label="Situation matrimoniale" value={data.statut_matrimonial} />
        <ReviewRow label="Nationalité" value={countryName(data.nationalite)} />
      </ReviewSection>

      <ReviewSection title="Coordonnées" step={2} onEditStep={onEditStep}>
        <ReviewRow label="Adresse e-mail" value={data.email} emphasized />
        <ReviewRow label="Téléphone principal" value={data.phone_e164} />
        {(data.indicatif2 || data.telephone2) && (
          <ReviewRow
            label="Téléphone secondaire"
            value={`${data.indicatif2} ${data.telephone2}`.trim()}
          />
        )}
        <ReviewRow label="Pays d’origine" value={countryName(data.pays_origine)} />
        <ReviewRow label="Pays de résidence" value={countryName(data.pays_residence)} />
        {data.region && <ReviewRow label="Région" value={data.region} />}
        {data.departement && <ReviewRow label="Département" value={data.departement} />}
        <ReviewRow
          label="Adresse de résidence"
          value={`${data.adresse}, ${data.ville_residence}`}
        />
      </ReviewSection>

      <ReviewSection title="Parcours académique et professionnel" step={3} onEditStep={onEditStep}>
        <ReviewRow label="Diplôme" value={data.diplome_obtenu} />
        <ReviewRow label="Établissement" value={data.institut} />
        <ReviewRow label="Spécialité du diplôme" value={data.specialite_diplome} />
        <ReviewRow label="Année d’obtention" value={String(data.annee_diplome)} />
        <ReviewRow label="Situation actuelle" value={statusLabel} />
        {data.fonction_actuelle && <ReviewRow label="Fonction" value={data.fonction_actuelle} />}
        {data.employeur && <ReviewRow label="Employeur" value={data.employeur} />}
        {data.adresse_employeur && (
          <ReviewRow label="Adresse de l’employeur" value={data.adresse_employeur} />
        )}
        {data.tel_employeur && (
          <ReviewRow label="Téléphone de l’employeur" value={data.tel_employeur} />
        )}
        <ReviewRow label="Comment vous avez connu le PSSFP" value={data.moyen_connaissance} />
        {data.moyen_connaissance_detail && (
          <ReviewRow label="Précision" value={data.moyen_connaissance_detail} />
        )}
      </ReviewSection>

      <ReviewSection title="Sécurité et engagements" step={4} onEditStep={onEditStep}>
        <ReviewRow label="PIN" value="Défini et masqué pour votre sécurité" />
        <ReviewRow label="Certification sur l’honneur" value="Acceptée" />
        <ReviewRow label="Conditions d’utilisation" value="Acceptées" />
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
        Votre PIN n’est jamais affiché dans ce récapitulatif ni enregistré dans votre navigateur.
      </div>
    </div>
  );
}

function ReviewSection({
  title,
  step,
  onEditStep,
  children,
}: {
  title: string;
  step: 1 | 2 | 3 | 4;
  onEditStep: (step: 1 | 2 | 3 | 4) => void;
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
          Modifier
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
