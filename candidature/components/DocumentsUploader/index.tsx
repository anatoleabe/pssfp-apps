'use client';

import { useRef, useState, useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { deleteDocumentAction, uploadDocumentAction } from '@/app/dossier/pieces/actions';
import type { CandidatureDocumentItem, CandidatureDocumentType } from '@/lib/api/client';

const MAX_BYTES = 5 * 1024 * 1024;
const ACCEPTED = ['application/pdf', 'image/jpeg', 'image/png'];

const TYPE_OPTIONS: Array<{ value: CandidatureDocumentType; label: string }> = [
  { value: 'diplome', label: 'Diplôme / attestation de réussite' },
  { value: 'acte_naissance', label: 'Acte de naissance' },
  { value: 'releves_notes', label: 'Relevé de notes' },
  { value: 'cv', label: 'CV' },
  { value: 'lettre_motivation', label: 'Lettre de motivation' },
  { value: 'attestation_employeur', label: 'Attestation employeur' },
  { value: 'autre', label: 'Autre pièce' },
];

function typeLabel(type: CandidatureDocumentType): string {
  return TYPE_OPTIONS.find((o) => o.value === type)?.label ?? type;
}

function formatSize(bytes: number): string {
  return `${(bytes / 1024 / 1024).toFixed(1)} Mo`;
}

interface DocumentsUploaderProps {
  initialDocuments: CandidatureDocumentItem[];
  isLocked: boolean;
}

export function DocumentsUploader({ initialDocuments, isLocked }: DocumentsUploaderProps): JSX.Element {
  const router = useRouter();
  const inputRef = useRef<HTMLInputElement>(null);
  const [documents, setDocuments] = useState<CandidatureDocumentItem[]>(initialDocuments);
  const [selectedType, setSelectedType] = useState<CandidatureDocumentType>('diplome');
  const [error, setError] = useState<string | null>(null);
  const [deletingUuid, setDeletingUuid] = useState<string | null>(null);
  const [isPending, startTransition] = useTransition();

  const validateClientSide = (file: File): string | null => {
    if (!ACCEPTED.includes(file.type)) {
      return 'Format non supporté. Utilisez PDF, JPG ou PNG.';
    }
    if (file.size > MAX_BYTES) {
      return `Fichier trop volumineux (${formatSize(file.size)}). Maximum 5 Mo.`;
    }
    return null;
  };

  const onSelectFile = (e: React.ChangeEvent<HTMLInputElement>): void => {
    const file = e.target.files?.[0];
    if (!file) return;

    const clientError = validateClientSide(file);
    if (clientError !== null) {
      setError(clientError);
      if (inputRef.current) inputRef.current.value = '';
      return;
    }

    setError(null);
    const fd = new FormData();
    fd.append('fichier', file);
    fd.append('type', selectedType);

    startTransition(async () => {
      const r = await uploadDocumentAction(fd);
      if (inputRef.current) inputRef.current.value = '';
      if (r.ok && r.document) {
        setDocuments((prev) => [r.document as CandidatureDocumentItem, ...prev]);
        router.refresh();
      } else {
        setError(r.message);
      }
    });
  };

  const removeDocument = (uuid: string): void => {
    setError(null);
    setDeletingUuid(uuid);
    startTransition(async () => {
      const r = await deleteDocumentAction(uuid);
      setDeletingUuid(null);
      if (r.ok) {
        setDocuments((prev) => prev.filter((d) => d.uuid !== uuid));
        router.refresh();
      } else {
        setError(r.message);
      }
    });
  };

  return (
    <div className="space-y-6">
      <p className="rounded-md border border-[#D4AF6A]/40 bg-[#FFFBEA] p-4 text-sm text-[#666]">
        Ces pièces sont <strong>optionnelles</strong> à ce stade : vous pouvez les ajouter ici, ou
        les apporter directement au bureau de la scolarité (Yaoundé-Messa, porte 231) au moment du
        dépôt de votre dossier. Elles ne bloquent pas la soumission de votre candidature.
      </p>

      {documents.length > 0 && (
        <ul className="space-y-2" data-testid="documents-list">
          {documents.map((doc) => (
            <li
              key={doc.uuid}
              className="flex items-center justify-between gap-3 rounded-md border border-gray-200 bg-white p-3"
            >
              <div className="min-w-0">
                <p className="text-sm font-medium text-[#333]">{typeLabel(doc.type)}</p>
                <p className="truncate text-xs text-[#666]">
                  {doc.original_filename} — {formatSize(doc.size)}
                </p>
              </div>
              <div className="flex shrink-0 items-center gap-3">
                <a
                  href={doc.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-sm text-[#4A2E67] underline hover:text-[#5C3A7E]"
                >
                  Voir
                </a>
                {!isLocked && (
                  <button
                    type="button"
                    onClick={() => removeDocument(doc.uuid)}
                    disabled={isPending && deletingUuid === doc.uuid}
                    data-testid={`document-delete-${doc.uuid}`}
                    className="text-sm text-red-700 underline hover:text-red-800 disabled:opacity-60"
                  >
                    {isPending && deletingUuid === doc.uuid ? 'Suppression…' : 'Supprimer'}
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {!isLocked && (
        <div className="flex flex-wrap items-end gap-3 rounded-md border border-dashed border-gray-300 p-4">
          <div>
            <label htmlFor="document-type-select" className="mb-1 block text-sm font-medium text-[#333]">
              Type de pièce
            </label>
            <select
              id="document-type-select"
              value={selectedType}
              onChange={(e) => setSelectedType(e.target.value as CandidatureDocumentType)}
              className="h-11 rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
              data-testid="document-type-select"
            >
              {TYPE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </div>

          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            disabled={isPending}
            data-testid="document-add-button"
            className="inline-flex h-11 items-center gap-2 rounded-md bg-[#4A2E67] px-4 text-sm font-medium text-white hover:bg-[#5C3A7E] focus:outline-none focus:ring-2 focus:ring-[#4A2E67] focus:ring-offset-2 disabled:opacity-60"
          >
            <span aria-hidden="true">+</span>
            {isPending && deletingUuid === null ? 'Envoi…' : 'Ajouter une pièce'}
          </button>
          <input
            ref={inputRef}
            type="file"
            accept="application/pdf,image/jpeg,image/png"
            onChange={onSelectFile}
            className="sr-only"
            data-testid="document-file-input"
            aria-label="Sélectionner le fichier de la pièce"
          />
          <p className="w-full text-xs text-[#666]">PDF, JPG ou PNG, max 5 Mo par fichier.</p>
        </div>
      )}

      {isLocked && documents.length === 0 && (
        <p role="status" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          Votre dossier est verrouillé : les pièces ne peuvent plus être ajoutées.
        </p>
      )}

      {error && (
        <p role="alert" data-testid="document-error" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  );
}
