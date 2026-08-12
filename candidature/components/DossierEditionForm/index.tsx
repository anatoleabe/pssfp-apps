'use client';

import { useCallback, useEffect, useMemo, useRef, useState, useTransition } from 'react';
import { useTranslations } from 'next-intl';
import { Link } from '@/navigation';
import { useRouter } from '@/navigation';
import { saveDossierFieldsAction } from '@/app/[locale]/dossier/edition/actions';
import { DiplomeSelect } from '@/components/DiplomeSelect';
import { EmployeurPublicSelect } from '@/components/EmployeurPublicSelect';
import { InstitutSelect } from '@/components/InstitutSelect';
import { PaysRegionDepartementSelect } from '@/components/PaysRegionDepartementSelect';
import { SearchableSelect } from '@/components/SearchableSelect';
import type { MyCandidature } from '@/lib/api/client';
import type { EditableField, EditableFields, EditableValue } from '@/lib/dossier/editableFields';
import { MOYENS_CONNAISSANCE, STATUT_ACTUEL_OPTIONS, isPublicEmploymentStatus, needsEmployer } from '@/lib/dossier/options';
import type { Diplome, EmployeurPublicGroup, Pays, Specialite, UniversitePays } from '@/lib/api/types';
import { Card, Field, inputCls, type FormState, type SectionPropsBase } from './primitives';
import { SectionDiplome } from './SectionDiplome';

const DEBOUNCE_MS = 2000;
const MAX_RETRIES = 3;
const RETRY_BACKOFF_MS = [1000, 2000, 4000];

type SaveStatus =
  | { kind: 'idle' }
  | { kind: 'saving' }
  | { kind: 'saved'; at: number }
  | { kind: 'error'; message: string }
  | { kind: 'locked' };

interface DossierEditionFormProps {
  candidature: MyCandidature;
  pays: Pays[];
  specialites: Specialite[];
  diplomes: Diplome[];
  universites: UniversitePays[];
  employeursPublics: EmployeurPublicGroup[];
  focusField: EditableField | null;
}

function buildInitialState(c: MyCandidature): FormState {
  return {
    civilite: c.civilite ?? '',
    nom: c.nom ?? '',
    prenom: c.prenom ?? '',
    epouse: c.epouse ?? '',
    date_naissance: c.date_naissance ?? '',
    lieu_naissance: c.lieu_naissance ?? '',
    genre: c.genre ?? '',
    statut_matrimonial: c.statut_matrimonial ?? '',
    nationalite: c.nationalite ?? '',
    specialite: c.specialite ?? '',
    type_etude: c.type_etude ?? 'presentiel',
    premiere_langue: c.premiere_langue ?? 'fr',
    pays_origine: c.pays_origine ?? '',
    pays_residence: c.pays_residence ?? '',
    region: c.region ?? '',
    departement: c.departement ?? '',
    adresse: c.adresse ?? '',
    ville_residence: c.ville_residence ?? '',
    indicatif1: c.indicatif1 ?? '',
    telephone1: c.telephone1 ?? '',
    indicatif2: c.indicatif2 ?? '',
    telephone2: c.telephone2 ?? '',
    email: c.email ?? '',
    diplome_obtenu: c.diplome_obtenu ?? '',
    institut: c.institut ?? '',
    specialite_diplome: c.specialite_diplome ?? '',
    annee_diplome: c.annee_diplome ?? '',
    diplome_requis: c.diplome_requis ?? '',
    annee_diplome_requis: c.annee_diplome_requis ?? '',
    domaine_diplome_requis: c.domaine_diplome_requis ?? '',
    specialite_diplome_requis: c.specialite_diplome_requis ?? '',
    institut_diplome_requis: c.institut_diplome_requis ?? '',
    autres_diplomes: c.autres_diplomes ?? [],
    formations_professionnelles: c.formations_professionnelles ?? [],
    statut_actuel: c.statut_actuel ?? '',
    fonction_actuelle: c.fonction_actuelle ?? '',
    employeur: c.employeur ?? '',
    adresse_employeur: c.adresse_employeur ?? '',
    tel_employeur: c.tel_employeur ?? '',
    moyen_connaissance: c.moyen_connaissance ?? '',
    moyen_connaissance_detail: c.moyen_connaissance_detail ?? '',
    engagement_nom: c.engagement_nom ?? '',
  };
}

export function DossierEditionForm({
  candidature,
  pays,
  specialites,
  diplomes,
  universites,
  employeursPublics,
  focusField,
}: DossierEditionFormProps): JSX.Element {
  const te = useTranslations('dossier.edition');
  const router = useRouter();
  const [form, setForm] = useState<FormState>(() => buildInitialState(candidature));
  const lastSavedRef = useRef<FormState>(buildInitialState(candidature));
  const [status, setStatus] = useState<SaveStatus>({ kind: 'idle' });
  const [fieldErrors, setFieldErrors] = useState<Partial<Record<EditableField, string>>>({});
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [, startTransition] = useTransition();

  const computeDiff = useCallback((next: FormState): EditableFields => {
    const diff: EditableFields = {};
    (Object.keys(next) as EditableField[]).forEach((k) => {
      const before = lastSavedRef.current[k];
      const after = next[k];
      // Comparaison structurelle : les blocs répétables sont des tableaux, une
      // égalité de référence les considérerait modifiés à chaque frappe.
      if (JSON.stringify(before) === JSON.stringify(after)) {
        return;
      }
      // PUT 'null' explicite quand l'utilisateur efface un champ texte optionnel.
      diff[k] = after === '' ? null : after;
    });
    return diff;
  }, []);

  const flush = useCallback(
    async (next: FormState, attempt = 0): Promise<void> => {
      const diff = computeDiff(next);
      if (Object.keys(diff).length === 0) {
        return;
      }
      setStatus({ kind: 'saving' });
      const r = await saveDossierFieldsAction(diff);
      if (r.ok) {
        lastSavedRef.current = next;
        setFieldErrors({});
        setStatus({ kind: 'saved', at: Date.now() });
        return;
      }
      if (r.errors) {
        setFieldErrors(r.errors as Partial<Record<EditableField, string>>);
      }
      if (r.status === 'locked') {
        setStatus({ kind: 'locked' });
        router.push('/dossier?reason=locked');
        return;
      }
      if (attempt < MAX_RETRIES) {
        const wait = RETRY_BACKOFF_MS[Math.min(attempt, RETRY_BACKOFF_MS.length - 1)];
        await new Promise((resolve) => setTimeout(resolve, wait));
        return flush(next, attempt + 1);
      }
      setStatus({ kind: 'error', message: r.message ?? 'Connexion perdue.' });
    },
    [computeDiff, router],
  );

  // Debounced auto-save : à chaque changement de form, on (re)programme un flush dans 2s.
  useEffect(() => {
    if (debounceRef.current) {
      clearTimeout(debounceRef.current);
    }
    debounceRef.current = setTimeout(() => {
      startTransition(() => {
        void flush(form);
      });
    }, DEBOUNCE_MS);
    return () => {
      if (debounceRef.current) {
        clearTimeout(debounceRef.current);
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form]);

  // Beforeunload : flush synchrone si possible (PUT en arrière-plan via sendBeacon).
  // En V1 simple : on prévient l'utilisateur s'il a un changement non sauvegardé.
  useEffect(() => {
    const onBeforeUnload = (e: BeforeUnloadEvent): void => {
      const diff = computeDiff(form);
      if (Object.keys(diff).length > 0) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [computeDiff, form]);

  // Focus sur le champ ciblé (depuis /dossier?focus=field_name → /dossier/edition?focus=field_name).
  useEffect(() => {
    if (!focusField) {
      return;
    }
    const el = document.querySelector(
      `[data-field="${focusField}"]`,
    ) as HTMLElement | null;
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      const input = el.querySelector('input,select,textarea') as HTMLElement | null;
      if (input) {
        input.focus();
      }
    }
  }, [focusField]);

  const setField = useCallback((field: EditableField, value: EditableValue): void => {
    setForm((prev) => ({ ...prev, [field]: value }));
  }, []);

  return (
    <div className="space-y-8">
      <SaveStatusBanner status={status} />

      <SectionIdentite
        form={form}
        errors={fieldErrors}
        pays={pays}
        specialites={specialites}
        setField={setField}
      />

      <SectionCoordonnees
        form={form}
        errors={fieldErrors}
        pays={pays}
        setField={setField}
        onPaysRegionChange={(next) => {
          setForm((prev) => ({
            ...prev,
            pays_residence: next.pays_residence,
            region: next.region,
            departement: next.departement,
          }));
        }}
      />

      <SectionDiplome
        formVersion={candidature.form_version}
        form={form}
        errors={fieldErrors}
        setField={setField}
        diplomes={diplomes}
        universites={universites}
        employeursPublics={employeursPublics}
      />

      <SectionEngagement form={form} errors={fieldErrors} setField={setField} />

      <div className="flex justify-start">
        <Link
          href="/dossier"
          data-testid="edition-back-bottom"
          className="inline-flex h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm text-[#333] hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
        >
          {te('backToDossier')}
        </Link>
      </div>
    </div>
  );
}

// ---------- Sections ----------

/**
 * Liste déroulante d'indicatif téléphonique par pays (format « +237 · Cameroun »).
 * La valeur stockée est l'indicatif seul (ex. « +237 »). Si l'indicatif courant
 * n'est pas dans la liste (donnée héritée), une option « valeur actuelle » est
 * ajoutée pour ne pas l'écraser silencieusement.
 */
function IndicatifSelect({
  pays,
  value,
  onChange,
  testId,
  includeEmpty,
}: {
  pays: Pays[];
  value: string;
  onChange: (next: string) => void;
  testId?: string;
  includeEmpty?: boolean;
}): JSX.Element {
  const tf = useTranslations('dossier.fields');
  const to = useTranslations('options');
  const te = useTranslations('dossier.edition');
  const knownIndicatifs = new Set(pays.map((p) => p.indicatif));
  const hasUnknownCurrent = value !== '' && !knownIndicatifs.has(value);

  return (
    <select
      data-testid={testId}
      aria-label={tf('indicatif_generique')}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className={inputCls}
    >
      {(includeEmpty || value === '') && <option value="">{tf('indicatifPlaceholder')}</option>}
      {hasUnknownCurrent && <option value={value}>{value}</option>}
      {pays.map((p) => (
        <option key={p.code_iso} value={p.indicatif}>
          {p.indicatif} · {p.nom}
        </option>
      ))}
    </select>
  );
}

function SectionIdentite({
  form,
  errors,
  pays,
  specialites,
  setField,
}: SectionPropsBase & { pays: Pays[]; specialites: Specialite[] }): JSX.Element {
  const tf = useTranslations('dossier.fields');
  const to = useTranslations('options');
  const te = useTranslations('dossier.edition');
  return (
    <Card id="identite" title={te('sectionIdentite')} description="Vos informations personnelles et la spécialité demandée.">
      <Field field="specialite" label={tf('specialite')} error={errors.specialite}>
        <SearchableSelect
          ariaLabel="Spécialité demandée"
          testId="edit-specialite"
          value={String(form.specialite ?? '')}
          options={specialites.map((s) => ({ value: s.label, label: s.label }))}
          onChange={(v) => setField('specialite', v)}
        />
      </Field>

      {/* Champ « Second choix éventuel » retiré (audit A-03, décision du Comité
          de Pilotage du 30/07/2026 : le vœu est unique pour la P14). Il
          contredisait frontalement la règle « un seul vœu par candidature »
          annoncée sur la page d'accueil, sans qu'aucune règle d'arbitrage entre
          les deux vœux ne soit publiée. La colonne `second_choix` est conservée
          en base le temps de la campagne pour ne pas perdre les valeurs déjà
          saisies ; sa suppression est à programmer après la clôture. */}

      <div className="grid gap-4 md:grid-cols-2">
        <Field field="type_etude" label={tf('type_etude')} error={errors.type_etude}>
          <select
            data-testid="edit-type-etude"
            value={String(form.type_etude ?? 'presentiel')}
            onChange={(e) => setField('type_etude', e.target.value)}
            className={inputCls}
          >
            <option value="presentiel">{to('presentiel')}</option>
            <option value="distanciel">{to('distanciel')}</option>
          </select>
        </Field>
        <Field field="premiere_langue" label={tf('premiere_langue')} error={errors.premiere_langue}>
          <select
            value={String(form.premiere_langue ?? 'fr')}
            onChange={(e) => setField('premiere_langue', e.target.value)}
            className={inputCls}
          >
            <option value="fr">{to('langueFr')}</option>
            <option value="en">{to('langueEn')}</option>
          </select>
        </Field>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <Field field="civilite" label={tf('civilite')} error={errors.civilite}>
          <select
            data-testid="edit-civilite"
            value={String(form.civilite ?? 'M.')}
            onChange={(e) => setField('civilite', e.target.value)}
            className={inputCls}
          >
            <option>M.</option>
            <option>Mme</option>
          </select>
        </Field>
        <Field field="prenom" label={tf('prenom')} error={errors.prenom}>
          <input
            data-testid="edit-prenom"
            type="text"
            value={String(form.prenom ?? '')}
            onChange={(e) => setField('prenom', e.target.value)}
            className={inputCls}
          />
        </Field>
        <Field field="nom" label={tf('nom')} error={errors.nom}>
          <input
            data-testid="edit-nom"
            type="text"
            value={String(form.nom ?? '')}
            onChange={(e) => setField('nom', e.target.value)}
            className={inputCls}
          />
        </Field>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <Field field="epouse" label={tf('epouse')} error={errors.epouse}>
          <input
            type="text"
            value={String(form.epouse ?? '')}
            onChange={(e) => setField('epouse', e.target.value)}
            className={inputCls}
          />
        </Field>
        <Field field="date_naissance" label={tf('date_naissance')} error={errors.date_naissance}>
          <input
            data-testid="edit-date-naissance"
            type="date"
            value={String(form.date_naissance ?? '')}
            onChange={(e) => setField('date_naissance', e.target.value)}
            max={new Date().toISOString().slice(0, 10)}
            className={inputCls}
          />
        </Field>
        <Field field="genre" label={tf('genre')} error={errors.genre}>
          <select
            value={String(form.genre ?? 'M')}
            onChange={(e) => setField('genre', e.target.value)}
            className={inputCls}
          >
            <option value="M">{to('genreM')}</option>
            <option value="F">{to('genreF')}</option>
            <option value="autre">{to('genreAutre')}</option>
          </select>
        </Field>
      </div>

      <Field field="lieu_naissance" label={tf('lieu_naissance')} error={errors.lieu_naissance}>
        <input
          data-testid="edit-lieu-naissance"
          type="text"
          value={String(form.lieu_naissance ?? '')}
          onChange={(e) => setField('lieu_naissance', e.target.value)}
          className={inputCls}
        />
      </Field>

      <div className="grid gap-4 md:grid-cols-2">
        <Field field="statut_matrimonial" label={tf('statut_matrimonial')} error={errors.statut_matrimonial}>
          <select
            value={String(form.statut_matrimonial ?? 'Célibataire')}
            onChange={(e) => setField('statut_matrimonial', e.target.value)}
            className={inputCls}
          >
            <option value="Célibataire">{to('marital.celibataire')}</option>
            <option value="Marié(e)">{to('marital.marie')}</option>
            <option value="Divorcé(e)">{to('marital.divorce')}</option>
            <option value="Veuf / Veuve">{to('marital.veuf')}</option>
            <option value="Autre">{to('marital.autre')}</option>
          </select>
        </Field>
        <Field field="nationalite" label={tf('nationalite')} error={errors.nationalite}>
          <SearchableSelect
            ariaLabel="Nationalité"
            value={String(form.nationalite ?? '')}
            options={pays.map((p) => ({ value: p.code_iso, label: p.nom }))}
            onChange={(v) => setField('nationalite', v)}
          />
        </Field>
      </div>
    </Card>
  );
}

function SectionCoordonnees({
  form,
  errors,
  pays,
  setField,
  onPaysRegionChange,
}: SectionPropsBase & {
  pays: Pays[];
  onPaysRegionChange: (next: { pays_residence: string; region: string; departement: string }) => void;
}): JSX.Element {
  const tf = useTranslations('dossier.fields');
  const to = useTranslations('options');
  const te = useTranslations('dossier.edition');
  return (
    <Card id="coordonnees" title={te('sectionCoordonnees')} description="Adresse, contact secondaire, email.">
      <Field field="pays_origine" label={tf('pays_origine')} error={errors.pays_origine}>
        <SearchableSelect
          ariaLabel="Pays d'origine"
          value={String(form.pays_origine ?? '')}
          options={pays.map((p) => ({ value: p.code_iso, label: p.nom }))}
          onChange={(v) => setField('pays_origine', v)}
        />
      </Field>

      <PaysRegionDepartementSelect
        initialPays={pays}
        value={{
          pays_residence: String(form.pays_residence ?? 'CM'),
          region: String(form.region ?? ''),
          departement: String(form.departement ?? ''),
        }}
        onChange={onPaysRegionChange}
      />

      <div className="grid gap-4 md:grid-cols-2">
        <Field field="adresse" label={tf('adresse')} error={errors.adresse}>
          <input
            data-testid="edit-adresse"
            type="text"
            value={String(form.adresse ?? '')}
            onChange={(e) => setField('adresse', e.target.value)}
            className={inputCls}
          />
        </Field>
        <Field field="ville_residence" label={tf('ville_residence')} error={errors.ville_residence}>
          <input
            type="text"
            value={String(form.ville_residence ?? '')}
            onChange={(e) => setField('ville_residence', e.target.value)}
            className={inputCls}
          />
        </Field>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Field field="indicatif1" label={tf('indicatif1')} error={errors.indicatif1}>
          <IndicatifSelect
            pays={pays}
            testId="edit-indicatif1"
            value={String(form.indicatif1 ?? '')}
            onChange={(v) => setField('indicatif1', v)}
          />
        </Field>
        <Field field="telephone1" label={tf('telephone1')} error={errors.telephone1}>
          <input
            data-testid="edit-telephone1"
            type="tel"
            inputMode="numeric"
            value={String(form.telephone1 ?? '')}
            onChange={(e) => setField('telephone1', e.target.value.replace(/\D/g, ''))}
            placeholder="691234567"
            className={inputCls}
          />
        </Field>
      </div>

      <p
        className="rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-[#666]"
        role="note"
      >
        Le numéro qui vous sert d'identifiant de connexion est distinct et ne peut être modifié
        depuis ce formulaire. Contactez le PSSFP en cas de nécessité.
      </p>

      <div className="grid gap-4 md:grid-cols-2">
        <Field field="indicatif2" label={tf('indicatif2')} error={errors.indicatif2}>
          <IndicatifSelect
            pays={pays}
            testId="edit-indicatif2"
            value={String(form.indicatif2 ?? '')}
            onChange={(v) => setField('indicatif2', v)}
            includeEmpty
          />
        </Field>
        <Field field="telephone2" label={tf('telephone2')} error={errors.telephone2}>
          <input
            type="tel"
            inputMode="numeric"
            value={String(form.telephone2 ?? '')}
            onChange={(e) => setField('telephone2', e.target.value.replace(/\D/g, ''))}
            className={inputCls}
          />
        </Field>
      </div>

      <Field field="email" label={tf('email')} error={errors.email}>
        <input
          data-testid="edit-email"
          type="email"
          required
          autoComplete="email"
          value={String(form.email ?? '')}
          onChange={(e) => setField('email', e.target.value)}
          className={inputCls}
        />
        <span className="mt-1 block text-xs text-[#666]">
          {te('emailHint')}
        </span>
      </Field>
    </Card>
  );
}

function SectionEngagement({ form, errors, setField }: SectionPropsBase): JSX.Element {
  const tf = useTranslations('dossier.fields');
  const to = useTranslations('options');
  const te = useTranslations('dossier.edition');
  const fullName = `${String(form.prenom ?? '').trim()} ${String(form.nom ?? '').trim()}`.trim();

  return (
    <Card
      id="engagement"
      title={te('sectionEngagement')}
      description="Re-signez en tapant exactement votre prénom suivi de votre nom."
    >
      <p className="rounded-md border border-[#D4AF6A]/40 bg-[#FFFBEA] p-3 text-xs text-[#666]" role="note">
        Votre PIN n'est pas modifiable depuis ce formulaire. En cas d'oubli, utilisez la
        page « PIN oublié » depuis l'écran de connexion.
      </p>
      <Field
        field="engagement_nom"
        label={fullName ? `Signature numérique (tapez « ${fullName} »)` : 'Signature numérique'}
        error={errors.engagement_nom}
      >
        <input
          data-testid="edit-engagement-nom"
          type="text"
          value={String(form.engagement_nom ?? '')}
          onChange={(e) => setField('engagement_nom', e.target.value)}
          className={inputCls}
        />
      </Field>
    </Card>
  );
}

// ---------- Save status banner ----------

function SaveStatusBanner({ status }: { status: SaveStatus }): JSX.Element {
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (status.kind === 'saved') {
      const id = setInterval(() => setTick((n) => n + 1), 5000);
      return () => clearInterval(id);
    }
    return () => {
      // noop
    };
  }, [status.kind]);

  const message = useMemo((): { tone: 'idle' | 'saving' | 'saved' | 'error'; text: string } => {
    void tick;
    switch (status.kind) {
      case 'idle':
        return { tone: 'idle', text: 'Vos modifications sont enregistrées automatiquement.' };
      case 'saving':
        return { tone: 'saving', text: 'Enregistrement…' };
      case 'saved': {
        const seconds = Math.floor((Date.now() - status.at) / 1000);
        if (seconds < 5) {
          return { tone: 'saved', text: 'Enregistré à l\'instant.' };
        }
        if (seconds < 60) {
          return { tone: 'saved', text: `Enregistré il y a ${seconds} s.` };
        }
        const minutes = Math.floor(seconds / 60);
        return { tone: 'saved', text: `Enregistré il y a ${minutes} min.` };
      }
      case 'error':
        return { tone: 'error', text: status.message };
      case 'locked':
        return { tone: 'error', text: 'Dossier verrouillé.' };
    }
  }, [status, tick]);

  const cls =
    message.tone === 'error'
      ? 'border-red-300 bg-red-50 text-red-700'
      : message.tone === 'saving'
        ? 'border-amber-300 bg-amber-50 text-amber-900'
        : message.tone === 'saved'
          ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
          : 'border-gray-200 bg-gray-50 text-[#666]';

  // Errors / locked → assertif (alert) ; saving / saved → polite (status).
  // Cf. revue a11y PR H : éviter qu'une perte de connexion passe inaperçue.
  const isUrgent = message.tone === 'error';

  return (
    <div
      role={isUrgent ? 'alert' : 'status'}
      aria-live={isUrgent ? 'assertive' : 'polite'}
      data-testid="edition-save-status"
      className={`sticky top-2 z-10 rounded-md border p-3 text-sm ${cls}`}
    >
      {message.text}
    </div>
  );
}
