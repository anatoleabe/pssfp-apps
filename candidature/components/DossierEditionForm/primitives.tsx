'use client';

import type { EditableField, EditableValue } from '@/lib/dossier/editableFields';

/**
 * Primitives partagées par les sections du formulaire d'édition du dossier.
 *
 * Extraites de `index.tsx` en août 2026 : le fichier dépassait les 800 lignes
 * du plafond projet, et `SectionDiplome` devait être isolée pour accueillir le
 * bloc « diplôme requis ».
 */

export type FormState = Record<EditableField, EditableValue>;

export interface SectionPropsBase {
  form: FormState;
  /**
   * Les clés à chemin complet (`autres_diplomes.0.intitule`) viennent des blocs
   * répétables ; l'index de signature les autorise sans perdre l'autocomplétion
   * sur les champs connus.
   */
  errors: Partial<Record<EditableField, string>> & Record<string, string | undefined>;
  setField: (field: EditableField, value: EditableValue) => void;
}

export const inputCls =
  'h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30';

export function Card({
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
      <h2 id={`${id}-heading`} className="font-heading text-lg font-bold text-[#4A2E67]">
        {title}
      </h2>
      {description && <p className="mt-1 text-sm text-[#666]">{description}</p>}
      <div className="mt-5 space-y-5">{children}</div>
    </section>
  );
}

export function Field({
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
