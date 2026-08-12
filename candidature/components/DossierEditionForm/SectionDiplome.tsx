'use client';

import { useTranslations } from 'next-intl';

import { AutresDiplomesEtFormations } from '@/components/diplomes/AutresDiplomesEtFormations';
import { DiplomeSelect } from '@/components/DiplomeSelect';
import { EmployeurPublicSelect } from '@/components/EmployeurPublicSelect';
import { InstitutSelect } from '@/components/InstitutSelect';
import type { Diplome, EmployeurPublicGroup, UniversitePays } from '@/lib/api/types';
import type { AutreDiplomeRow, FormationProRow } from '@/lib/diplomes/rows';
import {
  DIPLOME_REQUIS_OPTIONS,
  DOMAINE_DIPLOME_OPTIONS,
  MOYENS_CONNAISSANCE,
  STATUT_ACTUEL_OPTIONS,
  isPublicEmploymentStatus,
  needsEmployer,
  needsSpecialiteDiplomeRequis,
} from '@/lib/dossier/options';
import { Card, Field, inputCls, type SectionPropsBase } from './primitives';

/**
 * Section « Parcours académique et activité actuelle ».
 *
 * `formVersion` pilote l'affichage du bloc « diplôme requis » et des blocs
 * répétables : un dossier antérieur à la mise en production d'août 2026
 * (form_version = 1) voit exactement le formulaire qu'il a toujours eu.
 */
export function SectionDiplome({
  form,
  errors,
  setField,
  formVersion,
  diplomes,
  universites,
  employeursPublics,
}: SectionPropsBase & {
  formVersion: number;
  diplomes: Diplome[];
  universites: UniversitePays[];
  employeursPublics: EmployeurPublicGroup[];
}): JSX.Element {
  const tf = useTranslations('dossier.fields');
  const to = useTranslations('options');
  const te = useTranslations('dossier.edition');
  const showEmployer = needsEmployer(String(form.statut_actuel ?? ''));
  const usePublicSelect = isPublicEmploymentStatus(String(form.statut_actuel ?? ''));

  return (
    <Card id="diplome" title={te('sectionDiplome')} description="Parcours académique et activité actuelle.">
      <div className="grid gap-4 md:grid-cols-2">
        <Field field="diplome_obtenu" label={tf('diplome_obtenu')} error={undefined}>
          <DiplomeSelect
            diplomes={diplomes}
            value={String(form.diplome_obtenu ?? '')}
            onChange={(v) => setField('diplome_obtenu', v)}
            error={errors.diplome_obtenu}
          />
        </Field>
        <Field field="annee_diplome" label={tf('annee_diplome')} error={errors.annee_diplome}>
          <input
            data-testid="edit-annee-diplome"
            type="number"
            inputMode="numeric"
            min={1950}
            max={new Date().getFullYear()}
            value={form.annee_diplome === '' ? '' : String(form.annee_diplome ?? '')}
            onChange={(e) => setField('annee_diplome', e.target.value === '' ? '' : Number(e.target.value))}
            className={inputCls}
          />
        </Field>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Field field="institut" label={tf('institut')} error={undefined}>
          <InstitutSelect
            universites={universites}
            value={String(form.institut ?? '')}
            onChange={(v) => setField('institut', v)}
            error={errors.institut}
          />
        </Field>
        <Field field="specialite_diplome" label={tf('specialite_diplome')} error={errors.specialite_diplome}>
          <input
            type="text"
            value={String(form.specialite_diplome ?? '')}
            onChange={(e) => setField('specialite_diplome', e.target.value)}
            className={inputCls}
          />
        </Field>
      </div>

      {formVersion >= 2 && (
        <>
          <div className="grid gap-4 md:grid-cols-2">
            <Field field="diplome_requis" label={tf('diplome_requis')} error={errors.diplome_requis}>
              <select
                data-testid="edit-diplome-requis"
                value={String(form.diplome_requis ?? '')}
                onChange={(e) => setField('diplome_requis', e.target.value)}
                className={inputCls}
              >
                <option value="">{to('choose')}</option>
                {DIPLOME_REQUIS_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </Field>
            <Field
              field="annee_diplome_requis"
              label={tf('annee_diplome_requis')}
              error={errors.annee_diplome_requis}
            >
              <input
                data-testid="edit-annee-diplome-requis"
                type="number"
                inputMode="numeric"
                min={1950}
                max={new Date().getFullYear()}
                value={form.annee_diplome_requis === '' ? '' : String(form.annee_diplome_requis ?? '')}
                onChange={(e) =>
                  setField('annee_diplome_requis', e.target.value === '' ? '' : Number(e.target.value))
                }
                className={inputCls}
              />
            </Field>
          </div>

          <Field
            field="domaine_diplome_requis"
            label={tf('domaine_diplome_requis')}
            error={errors.domaine_diplome_requis}
          >
            <select
              data-testid="edit-domaine-diplome-requis"
              value={String(form.domaine_diplome_requis ?? '')}
              onChange={(e) => {
                const domaine = e.target.value;
                setField('domaine_diplome_requis', domaine);
                // Quitter « Autres » efface la spécialité : sans cela une valeur
                // masquée resterait enregistrée et s'imprimerait sur le récépissé.
                if (!needsSpecialiteDiplomeRequis(domaine)) {
                  setField('specialite_diplome_requis', '');
                }
              }}
              className={inputCls}
            >
              <option value="">{to('choose')}</option>
              {DOMAINE_DIPLOME_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </Field>

          {needsSpecialiteDiplomeRequis(String(form.domaine_diplome_requis ?? '')) && (
            <Field
              field="specialite_diplome_requis"
              label={tf('specialite_diplome_requis')}
              error={errors.specialite_diplome_requis}
            >
              <input
                data-testid="edit-specialite-diplome-requis"
                type="text"
                value={String(form.specialite_diplome_requis ?? '')}
                onChange={(e) => setField('specialite_diplome_requis', e.target.value)}
                className={inputCls}
              />
            </Field>
          )}

          <Field
            field="institut_diplome_requis"
            label={tf('institut_diplome_requis')}
            error={errors.institut_diplome_requis}
          >
            <InstitutSelect
              testId="edit-institut-diplome-requis"
              ariaLabel={tf('institut_diplome_requis')}
              universites={universites}
              value={String(form.institut_diplome_requis ?? '')}
              onChange={(v) => setField('institut_diplome_requis', v)}
              error={errors.institut_diplome_requis}
            />
          </Field>

          <AutresDiplomesEtFormations
            autresDiplomes={(form.autres_diplomes as AutreDiplomeRow[] | null) ?? []}
            formationsProfessionnelles={
              (form.formations_professionnelles as FormationProRow[] | null) ?? []
            }
            errors={errors}
            onChangeAutresDiplomes={(rows) => setField('autres_diplomes', rows)}
            onChangeFormations={(rows) => setField('formations_professionnelles', rows)}
          />
        </>
      )}

      <Field field="statut_actuel" label={tf('statut_actuel')} error={errors.statut_actuel}>
        <select
          data-testid="edit-statut-actuel"
          value={String(form.statut_actuel ?? '')}
          onChange={(e) => setField('statut_actuel', e.target.value)}
          className={inputCls}
        >
          <option value="">{to('choose')}</option>
          {STATUT_ACTUEL_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </Field>

      {showEmployer && (
        <div className="space-y-4 rounded-md border border-[#F4EFFA] bg-[#FAF7FF] p-4">
          <Field
            field="employeur"
            label={usePublicSelect ? 'Administration, entreprise ou établissement public *' : 'Employeur ou organisation *'}
            error={errors.employeur}
          >
            {usePublicSelect ? (
              <EmployeurPublicSelect
                groups={employeursPublics}
                value={String(form.employeur ?? '')}
                onChange={(value) => setField('employeur', value)}
                error={errors.employeur}
              />
            ) : (
              <input
                type="text"
                value={String(form.employeur ?? '')}
                onChange={(e) => setField('employeur', e.target.value)}
                placeholder={te('employeurPlaceholder')}
                className={inputCls}
              />
            )}
          </Field>
          <Field field="fonction_actuelle" label={tf('fonction_actuelle')} error={errors.fonction_actuelle}>
            <input
              type="text"
              value={String(form.fonction_actuelle ?? '')}
              onChange={(e) => setField('fonction_actuelle', e.target.value)}
              placeholder={te('fonctionPlaceholder')}
              className={inputCls}
            />
          </Field>
          <div className="grid gap-4 md:grid-cols-2">
            <Field field="adresse_employeur" label={tf('adresse_employeur')} error={errors.adresse_employeur}>
              <input
                type="text"
                value={String(form.adresse_employeur ?? '')}
                onChange={(e) => setField('adresse_employeur', e.target.value)}
                className={inputCls}
              />
            </Field>
            <Field field="tel_employeur" label={tf('tel_employeur')} error={errors.tel_employeur}>
              <input
                type="tel"
                value={String(form.tel_employeur ?? '')}
                onChange={(e) => setField('tel_employeur', e.target.value)}
                className={inputCls}
              />
            </Field>
          </div>
          <p className="text-xs text-[#666]">
            {te('attestationNotice')}
          </p>
        </div>
      )}

      <Field field="moyen_connaissance" label={tf('moyen_connaissance')} error={errors.moyen_connaissance}>
        <select
          value={String(form.moyen_connaissance ?? '')}
          onChange={(e) => setField('moyen_connaissance', e.target.value)}
          className={inputCls}
        >
          <option value="">{to('choose')}</option>
          {MOYENS_CONNAISSANCE.map((option) => <option key={option}>{option}</option>)}
        </select>
      </Field>

      {['Autre', 'Autre réseau social', 'Administration ou employeur', 'Université ou établissement d’enseignement', 'Collègue, ami ou membre de la famille'].includes(String(form.moyen_connaissance ?? '')) && (
        <Field field="moyen_connaissance_detail" label={tf('moyen_connaissance_detail')} error={errors.moyen_connaissance_detail}>
          <input
            type="text"
            value={String(form.moyen_connaissance_detail ?? '')}
            onChange={(event) => setField('moyen_connaissance_detail', event.target.value)}
            placeholder={te('sourcePlaceholder')}
            className={inputCls}
          />
        </Field>
      )}
    </Card>
  );
}
