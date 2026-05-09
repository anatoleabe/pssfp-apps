'use client';

import { useCallback, useEffect, useMemo, useRef, useState, useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { saveDossierFieldsAction } from '@/app/dossier/edition/actions';
import { PaysRegionDepartementSelect } from '@/components/PaysRegionDepartementSelect';
import { SearchableSelect } from '@/components/SearchableSelect';
import type { MyCandidature } from '@/lib/api/client';
import type { EditableField, EditableFields } from '@/lib/dossier/editableFields';
import type { Pays, Specialite } from '@/lib/api/types';

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
  focusField: EditableField | null;
}

type FormState = Record<EditableField, string | number | null>;

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
    second_choix: c.second_choix ?? '',
    type_etude: c.type_etude ?? 'presentiel',
    premiere_langue: c.premiere_langue ?? 'fr',
    pays_origine: c.pays_origine ?? '',
    pays_residence: c.pays_residence ?? '',
    region: c.region ?? '',
    departement: c.departement ?? '',
    adresse: c.adresse ?? '',
    ville_residence: c.ville_residence ?? '',
    indicatif2: c.indicatif2 ?? '',
    telephone2: c.telephone2 ?? '',
    email: c.email ?? '',
    diplome_obtenu: c.diplome_obtenu ?? '',
    institut: c.institut ?? '',
    specialite_diplome: c.specialite_diplome ?? '',
    annee_diplome: c.annee_diplome ?? '',
    statut_actuel: c.statut_actuel ?? '',
    employeur: c.employeur ?? '',
    adresse_employeur: c.adresse_employeur ?? '',
    tel_employeur: c.tel_employeur ?? '',
    moyen_connaissance: c.moyen_connaissance ?? '',
    engagement_nom: c.engagement_nom ?? '',
  };
}

export function DossierEditionForm({
  candidature,
  pays,
  specialites,
  focusField,
}: DossierEditionFormProps): JSX.Element {
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
      const normalizedBefore = before === '' ? '' : before;
      const normalizedAfter = after === '' ? '' : after;
      if (normalizedBefore !== normalizedAfter) {
        // PUT 'null' explicite quand l'utilisateur efface un champ optionnel.
        diff[k] = after === '' ? null : after;
      }
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

  const setField = useCallback((field: EditableField, value: string | number): void => {
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

      <SectionDiplome form={form} errors={fieldErrors} setField={setField} />

      <SectionEngagement form={form} errors={fieldErrors} setField={setField} />
    </div>
  );
}

// ---------- Sections ----------

function Card({
  id,
  title,
  description,
  children,
}: {
  id: string;
  title: string;
  description?: string;
  children: React.ReactNode;
}): JSX.Element {
  return (
    <section
      aria-labelledby={`${id}-heading`}
      className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
      data-testid={`edition-section-${id}`}
    >
      <h2
        id={`${id}-heading`}
        className="font-heading text-lg font-bold text-[#6B2FA0]"
      >
        {title}
      </h2>
      {description && <p className="mt-1 text-sm text-[#666]">{description}</p>}
      <div className="mt-5 space-y-5">{children}</div>
    </section>
  );
}

function Field({
  field,
  label,
  error,
  children,
}: {
  field: EditableField;
  label: string;
  error?: string;
  children: React.ReactNode;
}): JSX.Element {
  return (
    <label className="block" data-field={field}>
      <span className="mb-1 block text-sm font-medium text-[#333333]">{label}</span>
      {children}
      {error && (
        <span role="alert" className="mt-1 block text-xs text-red-700">
          {error}
        </span>
      )}
    </label>
  );
}

const inputCls =
  'h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#6B2FA0] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0]/30';

interface SectionPropsBase {
  form: FormState;
  errors: Partial<Record<EditableField, string>>;
  setField: (field: EditableField, value: string | number) => void;
}

function SectionIdentite({
  form,
  errors,
  pays,
  specialites,
  setField,
}: SectionPropsBase & { pays: Pays[]; specialites: Specialite[] }): JSX.Element {
  return (
    <Card id="identite" title="Identité & vœu" description="Vos informations personnelles et la spécialité demandée.">
      <Field field="specialite" label="Spécialité demandée" error={errors.specialite}>
        <SearchableSelect
          ariaLabel="Spécialité demandée"
          testId="edit-specialite"
          value={String(form.specialite ?? '')}
          options={specialites.map((s) => ({ value: s.label, label: s.label }))}
          onChange={(v) => setField('specialite', v)}
        />
      </Field>

      <Field field="second_choix" label="Second choix éventuel (optionnel)" error={errors.second_choix}>
        <input
          data-testid="edit-second-choix"
          type="text"
          value={String(form.second_choix ?? '')}
          onChange={(e) => setField('second_choix', e.target.value)}
          className={inputCls}
        />
      </Field>

      <div className="grid gap-4 md:grid-cols-2">
        <Field field="type_etude" label="Type d'études" error={errors.type_etude}>
          <select
            data-testid="edit-type-etude"
            value={String(form.type_etude ?? 'presentiel')}
            onChange={(e) => setField('type_etude', e.target.value)}
            className={inputCls}
          >
            <option value="presentiel">Présentiel</option>
            <option value="distanciel">Distanciel</option>
          </select>
        </Field>
        <Field field="premiere_langue" label="Première langue" error={errors.premiere_langue}>
          <select
            value={String(form.premiere_langue ?? 'fr')}
            onChange={(e) => setField('premiere_langue', e.target.value)}
            className={inputCls}
          >
            <option value="fr">Français</option>
            <option value="en">Anglais</option>
          </select>
        </Field>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <Field field="civilite" label="Civilité" error={errors.civilite}>
          <select
            data-testid="edit-civilite"
            value={String(form.civilite ?? 'M.')}
            onChange={(e) => setField('civilite', e.target.value)}
            className={inputCls}
          >
            <option>M.</option>
            <option>Mme</option>
            <option>Mlle</option>
          </select>
        </Field>
        <Field field="prenom" label="Prénom(s)" error={errors.prenom}>
          <input
            data-testid="edit-prenom"
            type="text"
            value={String(form.prenom ?? '')}
            onChange={(e) => setField('prenom', e.target.value)}
            className={inputCls}
          />
        </Field>
        <Field field="nom" label="Nom" error={errors.nom}>
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
        <Field field="epouse" label="Nom de jeune fille (si applicable)" error={errors.epouse}>
          <input
            type="text"
            value={String(form.epouse ?? '')}
            onChange={(e) => setField('epouse', e.target.value)}
            className={inputCls}
          />
        </Field>
        <Field field="date_naissance" label="Date de naissance" error={errors.date_naissance}>
          <input
            data-testid="edit-date-naissance"
            type="date"
            value={String(form.date_naissance ?? '')}
            onChange={(e) => setField('date_naissance', e.target.value)}
            max={new Date().toISOString().slice(0, 10)}
            className={inputCls}
          />
        </Field>
        <Field field="genre" label="Genre" error={errors.genre}>
          <select
            value={String(form.genre ?? 'M')}
            onChange={(e) => setField('genre', e.target.value)}
            className={inputCls}
          >
            <option value="M">Masculin</option>
            <option value="F">Féminin</option>
            <option value="autre">Autre</option>
          </select>
        </Field>
      </div>

      <Field field="lieu_naissance" label="Lieu de naissance" error={errors.lieu_naissance}>
        <input
          data-testid="edit-lieu-naissance"
          type="text"
          value={String(form.lieu_naissance ?? '')}
          onChange={(e) => setField('lieu_naissance', e.target.value)}
          className={inputCls}
        />
      </Field>

      <div className="grid gap-4 md:grid-cols-2">
        <Field field="statut_matrimonial" label="Situation matrimoniale" error={errors.statut_matrimonial}>
          <select
            value={String(form.statut_matrimonial ?? 'Célibataire')}
            onChange={(e) => setField('statut_matrimonial', e.target.value)}
            className={inputCls}
          >
            <option>Célibataire</option>
            <option>Marié(e)</option>
            <option>Divorcé(e)</option>
            <option>Veuf / Veuve</option>
            <option>Autre</option>
          </select>
        </Field>
        <Field field="nationalite" label="Nationalité" error={errors.nationalite}>
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
  return (
    <Card id="coordonnees" title="Coordonnées" description="Adresse, contact secondaire, email.">
      <Field field="pays_origine" label="Pays d'origine" error={errors.pays_origine}>
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
        <Field field="adresse" label="Adresse complète" error={errors.adresse}>
          <input
            data-testid="edit-adresse"
            type="text"
            value={String(form.adresse ?? '')}
            onChange={(e) => setField('adresse', e.target.value)}
            className={inputCls}
          />
        </Field>
        <Field field="ville_residence" label="Ville de résidence" error={errors.ville_residence}>
          <input
            type="text"
            value={String(form.ville_residence ?? '')}
            onChange={(e) => setField('ville_residence', e.target.value)}
            className={inputCls}
          />
        </Field>
      </div>

      <p
        className="rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-[#666]"
        role="note"
      >
        Votre numéro de téléphone principal sert d'identifiant de connexion et ne peut être
        modifié depuis ce formulaire. Contactez le PSSFP en cas de nécessité.
      </p>

      <div className="grid gap-4 md:grid-cols-2">
        <Field field="indicatif2" label="Indicatif téléphone secondaire" error={errors.indicatif2}>
          <input
            type="text"
            value={String(form.indicatif2 ?? '')}
            onChange={(e) => setField('indicatif2', e.target.value)}
            placeholder="+237"
            className={inputCls}
          />
        </Field>
        <Field field="telephone2" label="Téléphone secondaire (optionnel)" error={errors.telephone2}>
          <input
            type="tel"
            value={String(form.telephone2 ?? '')}
            onChange={(e) => setField('telephone2', e.target.value)}
            className={inputCls}
          />
        </Field>
      </div>

      <Field field="email" label="Email (optionnel)" error={errors.email}>
        <input
          data-testid="edit-email"
          type="email"
          value={String(form.email ?? '')}
          onChange={(e) => setField('email', e.target.value)}
          className={inputCls}
        />
      </Field>
    </Card>
  );
}

function SectionDiplome({ form, errors, setField }: SectionPropsBase): JSX.Element {
  const showEmployer = form.statut_actuel && form.statut_actuel !== 'Etudiant';

  return (
    <Card id="diplome" title="Diplôme & profession" description="Parcours académique et activité actuelle.">
      <div className="grid gap-4 md:grid-cols-2">
        <Field field="diplome_obtenu" label="Diplôme le plus élevé obtenu" error={errors.diplome_obtenu}>
          <input
            data-testid="edit-diplome-obtenu"
            type="text"
            value={String(form.diplome_obtenu ?? '')}
            onChange={(e) => setField('diplome_obtenu', e.target.value)}
            placeholder="Master, Licence…"
            className={inputCls}
          />
        </Field>
        <Field field="annee_diplome" label="Année d'obtention" error={errors.annee_diplome}>
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
        <Field field="institut" label="Établissement de délivrance" error={errors.institut}>
          <input
            type="text"
            value={String(form.institut ?? '')}
            onChange={(e) => setField('institut', e.target.value)}
            className={inputCls}
          />
        </Field>
        <Field field="specialite_diplome" label="Spécialité du diplôme" error={errors.specialite_diplome}>
          <input
            type="text"
            value={String(form.specialite_diplome ?? '')}
            onChange={(e) => setField('specialite_diplome', e.target.value)}
            className={inputCls}
          />
        </Field>
      </div>

      <Field field="statut_actuel" label="Situation actuelle" error={errors.statut_actuel}>
        <select
          data-testid="edit-statut-actuel"
          value={String(form.statut_actuel ?? '')}
          onChange={(e) => setField('statut_actuel', e.target.value)}
          className={inputCls}
        >
          <option value="">— Choisir —</option>
          <option value="Etudiant">Étudiant</option>
          <option value="Fonctionnaire-Contractuel">Fonctionnaire / Contractuel</option>
          <option value="Prive">Secteur privé</option>
        </select>
      </Field>

      {showEmployer && (
        <div className="space-y-4 rounded-md border border-[#EDE7F6] bg-[#FAF7FF] p-4">
          <Field field="employeur" label="Employeur" error={errors.employeur}>
            <input
              type="text"
              value={String(form.employeur ?? '')}
              onChange={(e) => setField('employeur', e.target.value)}
              className={inputCls}
            />
          </Field>
          <Field field="adresse_employeur" label="Adresse de l'employeur" error={errors.adresse_employeur}>
            <input
              type="text"
              value={String(form.adresse_employeur ?? '')}
              onChange={(e) => setField('adresse_employeur', e.target.value)}
              className={inputCls}
            />
          </Field>
          <Field field="tel_employeur" label="Téléphone employeur" error={errors.tel_employeur}>
            <input
              type="text"
              value={String(form.tel_employeur ?? '')}
              onChange={(e) => setField('tel_employeur', e.target.value)}
              className={inputCls}
            />
          </Field>
        </div>
      )}

      <Field field="moyen_connaissance" label="Comment avez-vous connu le PSSFP ? (optionnel)" error={errors.moyen_connaissance}>
        <select
          value={String(form.moyen_connaissance ?? '')}
          onChange={(e) => setField('moyen_connaissance', e.target.value)}
          className={inputCls}
        >
          <option value="">— Choisir —</option>
          <option>Site web</option>
          <option>Réseaux sociaux</option>
          <option>Recommandation</option>
          <option>Presse</option>
          <option>Ancien candidat</option>
          <option>Autre</option>
        </select>
      </Field>
    </Card>
  );
}

function SectionEngagement({ form, errors, setField }: SectionPropsBase): JSX.Element {
  const fullName = `${String(form.prenom ?? '').trim()} ${String(form.nom ?? '').trim()}`.trim();

  return (
    <Card
      id="engagement"
      title="Engagement"
      description="Re-signez en tapant exactement votre prénom suivi de votre nom."
    >
      <p className="rounded-md border border-[#C9A227]/40 bg-[#FFFBEA] p-3 text-xs text-[#666]" role="note">
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
