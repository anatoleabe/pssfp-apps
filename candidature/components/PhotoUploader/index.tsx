'use client';

import { useTranslations } from 'next-intl';

import { useCallback, useRef, useState, useTransition } from 'react';
import { useRouter } from '@/navigation';
import { uploadPhotoAction, deletePhotoAction } from '@/app/[locale]/dossier/photo/actions';
import { compressPhotoIfNeeded } from '@/lib/photo/compressImage';

const MAX_BYTES = 2 * 1024 * 1024;
const MIN_DIMENSION = 200;
const ACCEPTED = ['image/jpeg', 'image/png'];

type UploaderState =
  | { kind: 'idle' }
  | { kind: 'preview'; previewUrl: string; file: File; compressionNote: string | null }
  | { kind: 'uploading' }
  | { kind: 'success'; signedUrl: string }
  | { kind: 'error'; message: string };

interface PhotoUploaderProps {
  initialHasPhoto: boolean;
  initialSignedUrl: string | null;
  isLocked: boolean;
}

export function PhotoUploader({
  initialHasPhoto,
  initialSignedUrl,
  isLocked,
}: PhotoUploaderProps): JSX.Element {
  const tu = useTranslations('uploads.photo');
  const router = useRouter();
  const inputRef = useRef<HTMLInputElement>(null);
  const [state, setState] = useState<UploaderState>(
    initialHasPhoto && initialSignedUrl !== null
      ? { kind: 'success', signedUrl: initialSignedUrl }
      : { kind: 'idle' },
  );
  const [isPending, startTransition] = useTransition();
  const [isDragging, setIsDragging] = useState(false);

  const validateClientSide = useCallback(async (file: File): Promise<string | null> => {
    if (!ACCEPTED.includes(file.type)) {
      return 'Format non supporté. Utilisez JPG ou PNG.';
    }
    if (file.size > MAX_BYTES) {
      // N'arrive normalement plus : handleFile compresse en amont. Ce
      // message ne reste atteignable qu'en dernier recours (compression
      // échouée, ou fichier encore trop lourd après le palier le plus
      // agressif — cf. compressPhotoIfNeeded).
      return `Fichier trop volumineux (${(file.size / 1024 / 1024).toFixed(1)} Mo). Maximum 2 Mo.`;
    }
    const dims = await new Promise<{ w: number; h: number } | null>((resolve) => {
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = (): void => {
        URL.revokeObjectURL(url);
        resolve({ w: img.naturalWidth, h: img.naturalHeight });
      };
      img.onerror = (): void => {
        URL.revokeObjectURL(url);
        resolve(null);
      };
      img.src = url;
    });
    if (dims === null) {
      return 'Impossible de lire l\'image. Réessayez avec un autre fichier.';
    }
    if (dims.w < MIN_DIMENSION || dims.h < MIN_DIMENSION) {
      return `Image trop petite (${dims.w}×${dims.h} px). Minimum 200×200 px.`;
    }
    return null;
  }, []);

  const handleFile = useCallback(
    async (file: File): Promise<void> => {
      let candidate = file;
      let compressionNote: string | null = null;

      // La compression ne s'applique qu'aux fichiers dépassant la limite —
      // compressPhotoIfNeeded renvoie le fichier tel quel sinon, sans coût.
      try {
        const result = await compressPhotoIfNeeded(file);
        candidate = result.file;
        if (result.wasCompressed) {
          const before = (result.originalBytes / 1024 / 1024).toFixed(1);
          const after = (result.finalBytes / 1024 / 1024).toFixed(1);
          compressionNote = tu('compressed', { before, after });
        }
      } catch {
        // Compression indisponible (navigateur atypique, image corrompue) :
        // on retombe sur le fichier d'origine — validateClientSide affichera
        // le message « trop volumineux » s'il dépasse effectivement 2 Mo.
      }

      const err = await validateClientSide(candidate);
      if (err !== null) {
        setState({ kind: 'error', message: err });
        return;
      }
      const previewUrl = URL.createObjectURL(candidate);
      setState({ kind: 'preview', previewUrl, file: candidate, compressionNote });
    },
    [validateClientSide, tu],
  );

  const onSelectInput = (e: React.ChangeEvent<HTMLInputElement>): void => {
    const f = e.target.files?.[0];
    if (f) {
      void handleFile(f);
    }
  };

  const onDrop = (e: React.DragEvent<HTMLDivElement>): void => {
    e.preventDefault();
    setIsDragging(false);
    const f = e.dataTransfer.files?.[0];
    if (f) {
      void handleFile(f);
    }
  };

  const submit = (): void => {
    if (state.kind !== 'preview') {
      return;
    }
    const fd = new FormData();
    fd.append('photo', state.file);
    setState({ kind: 'uploading' });
    startTransition(async () => {
      const r = await uploadPhotoAction(fd);
      if (r.ok) {
        setState({ kind: 'success', signedUrl: r.signedUrl });
        router.refresh();
      } else {
        setState({ kind: 'error', message: r.message });
      }
    });
  };

  const reset = (): void => {
    setState({ kind: 'idle' });
    if (inputRef.current) {
      inputRef.current.value = '';
    }
  };

  const removePhoto = (): void => {
    startTransition(async () => {
      const r = await deletePhotoAction();
      if (r.ok) {
        setState({ kind: 'idle' });
        router.refresh();
      } else {
        setState({ kind: 'error', message: r.message });
      }
    });
  };

  return (
    <div className="grid gap-6 md:grid-cols-[260px_1fr]">
      <div
        data-testid="photo-preview"
        className="relative aspect-square overflow-hidden rounded-lg border border-gray-200 bg-gray-50"
      >
        {state.kind === 'preview' && (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={state.previewUrl} alt="" className="h-full w-full object-cover" />
        )}
        {state.kind === 'success' && (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={state.signedUrl} alt="" className="h-full w-full object-cover" />
        )}
        {(state.kind === 'idle' || state.kind === 'error') && (
          <div className="flex h-full w-full items-center justify-center text-sm text-gray-600">
            {tu('none')}
          </div>
        )}
        {state.kind === 'uploading' && (
          <div
            role="status"
            aria-live="polite"
            className="flex h-full w-full items-center justify-center bg-gray-50 text-sm text-[#4A2E67]"
          >
            {tu('uploading')}
          </div>
        )}
      </div>

      <div role="status" aria-live="polite" className="sr-only" data-testid="photo-status-live">
        {state.kind === 'success' && 'Photo enregistrée.'}
        {state.kind === 'preview' && 'Photo prête à envoyer.'}
        {state.kind === 'uploading' && 'Envoi en cours.'}
      </div>

      <div className="flex flex-col gap-4">
        {!isLocked && (state.kind === 'idle' || state.kind === 'error') && (
          <div
            role="region"
            aria-label={tu('dropzoneAria')}
            onDragOver={(e) => {
              e.preventDefault();
              setIsDragging(true);
            }}
            onDragLeave={() => setIsDragging(false)}
            onDrop={onDrop}
            className={`rounded-lg border-2 border-dashed p-6 text-sm transition-colors ${
              isDragging ? 'border-[#4A2E67] bg-[#F4EFFA]' : 'border-gray-300 bg-white'
            }`}
          >
            <p className="font-medium text-[#333]">{tu('dropHere')}</p>
            <p className="mt-1 text-[#666]">ou</p>
            <button
              type="button"
              onClick={() => inputRef.current?.click()}
              className="mt-3 inline-flex h-11 items-center rounded-md bg-[#4A2E67] px-4 text-sm font-medium text-white hover:bg-[#5C3A7E] focus:outline-none focus:ring-2 focus:ring-[#4A2E67] focus:ring-offset-2"
              data-testid="photo-pick"
            >
              {tu('choose')}
            </button>
            <input
              ref={inputRef}
              type="file"
              accept="image/jpeg,image/png"
              onChange={onSelectInput}
              className="sr-only"
              data-testid="photo-input"
              aria-label={tu('selectAria')}
            />
            <p className="mt-3 text-xs text-[#666]">
              {tu('hint')}
            </p>
          </div>
        )}

        {state.kind === 'preview' && !isLocked && (
          <div className="flex flex-col gap-3">
            {state.compressionNote && (
              <p data-testid="photo-compression-note" className="text-xs text-[#666]">
                {state.compressionNote}
              </p>
            )}
            <div className="flex flex-wrap gap-3">
              <button
                type="button"
                onClick={submit}
                disabled={isPending}
                className="inline-flex h-11 items-center rounded-md bg-[#4A2E67] px-5 text-sm font-medium text-white hover:bg-[#5C3A7E] focus:outline-none focus:ring-2 focus:ring-[#4A2E67] focus:ring-offset-2 disabled:opacity-60"
                data-testid="photo-submit"
              >
                {isPending ? 'Envoi…' : 'Enregistrer cette photo'}
              </button>
              <button
                type="button"
                onClick={reset}
                className="inline-flex h-11 items-center rounded-md border border-gray-300 bg-white px-5 text-sm text-[#333] hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[#4A2E67] focus:ring-offset-2"
              >
                {tu('chooseAnother')}
              </button>
            </div>
          </div>
        )}

        {state.kind === 'success' && !isLocked && (
          <div className="flex flex-wrap gap-3">
            <button
              type="button"
              onClick={() => inputRef.current?.click()}
              className="inline-flex h-11 items-center rounded-md bg-[#4A2E67] px-5 text-sm font-medium text-white hover:bg-[#5C3A7E] focus:outline-none focus:ring-2 focus:ring-[#4A2E67] focus:ring-offset-2"
              data-testid="photo-replace"
            >
              {tu('replace')}
            </button>
            <button
              type="button"
              onClick={removePhoto}
              disabled={isPending}
              className="inline-flex h-11 items-center rounded-md border border-red-300 bg-white px-5 text-sm text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-60"
              data-testid="photo-delete"
            >
              {isPending ? 'Suppression…' : 'Supprimer'}
            </button>
            <input
              ref={inputRef}
              type="file"
              accept="image/jpeg,image/png"
              onChange={onSelectInput}
              className="sr-only"
              data-testid="photo-input"
              aria-label={tu('selectNewAria')}
            />
          </div>
        )}

        {isLocked && (
          <p
            role="status"
            data-testid="photo-locked"
            className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
          >
            {tu('locked')}
          </p>
        )}

        {state.kind === 'error' && (
          <p
            role="alert"
            data-testid="photo-error"
            className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700"
          >
            {state.message}
          </p>
        )}
      </div>
    </div>
  );
}
