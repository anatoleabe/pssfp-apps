'use client';

/**
 * Compression photo côté client, avant envoi au serveur.
 *
 * Les photos prises directement au téléphone pèsent fréquemment 3 à 8 Mo
 * (haute résolution, JPEG peu compressé), bien au-delà de la limite serveur
 * de 2 Mo (`UploadPhotoRequest::rules`). Sans ce traitement, une partie des
 * candidats voyait sa photo rejetée sans recours simple — la plupart ne
 * savent pas redimensionner une image eux-mêmes, et abandonnaient le dépôt.
 *
 * La compression est automatique et silencieuse : le candidat choisit sa
 * photo telle quelle, elle est réduite si nécessaire avant l'envoi. API
 * Canvas native, aucune dépendance ajoutée.
 */

/** Marge sous la limite serveur de 2 Mo (UploadPhotoRequest::rules `max:2048`). */
const TARGET_MAX_BYTES = 1.8 * 1024 * 1024;

/** Paliers de redimensionnement (plus grand côté, en px), du moins au plus agressif. */
const DIMENSION_STEPS = [1600, 1200, 900, 700];

/** Paliers de qualité JPEG, du moins au plus agressif. */
const QUALITY_STEPS = [0.92, 0.85, 0.75, 0.65, 0.55];

export interface CompressPhotoResult {
  file: File;
  wasCompressed: boolean;
  originalBytes: number;
  finalBytes: number;
}

/**
 * Ne touche pas aux fichiers déjà sous la limite : c'est le cas le plus
 * fréquent, et cette fonction ne doit ajouter ni latence ni perte de qualité
 * quand ce n'est pas nécessaire.
 */
export async function compressPhotoIfNeeded(file: File): Promise<CompressPhotoResult> {
  if (file.size <= TARGET_MAX_BYTES) {
    return { file, wasCompressed: false, originalBytes: file.size, finalBytes: file.size };
  }

  const image = await loadImage(file);
  const nativeMax = Math.max(image.naturalWidth, image.naturalHeight);

  // Toujours essayer d'abord une simple recompression sans redimensionnement :
  // un JPEG peu compressé gagne souvent assez avec la seule qualité, et une
  // photo d'identité n'a pas besoin d'être agrandie ni inutilement réduite.
  const steps = Array.from(new Set([nativeMax, ...DIMENSION_STEPS].filter((d) => d <= nativeMax)))
    .sort((a, b) => b - a);

  let smallest: Blob | null = null;

  for (const maxDim of steps) {
    for (const quality of QUALITY_STEPS) {
      const blob = await drawAndEncode(image, maxDim, quality);
      if (smallest === null || blob.size < smallest.size) {
        smallest = blob;
      }
      if (blob.size <= TARGET_MAX_BYTES) {
        return toResult(file, blob);
      }
    }
  }

  // Dernier recours, en pratique jamais atteint pour une photo d'identité :
  // on renvoie la plus petite variante obtenue, même si elle dépasse encore
  // la limite. Le serveur affichera alors le message d'erreur habituel, sur
  // un fichier déjà bien plus léger que l'original.
  return toResult(file, smallest ?? file);
}

function toResult(original: File, output: Blob | File): CompressPhotoResult {
  const finalFile =
    output instanceof File
      ? output
      : new File([output], renameToJpg(original.name), { type: 'image/jpeg' });

  return {
    file: finalFile,
    wasCompressed: true,
    originalBytes: original.size,
    finalBytes: finalFile.size,
  };
}

function renameToJpg(name: string): string {
  return name.replace(/\.\w+$/, '') + '.jpg';
}

function loadImage(file: File): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = (): void => {
      URL.revokeObjectURL(url);
      resolve(img);
    };
    img.onerror = (): void => {
      URL.revokeObjectURL(url);
      reject(new Error('image_load_failed'));
    };
    img.src = url;
  });
}

function drawAndEncode(image: HTMLImageElement, maxDim: number, quality: number): Promise<Blob> {
  const scale = Math.min(1, maxDim / Math.max(image.naturalWidth, image.naturalHeight));
  const width = Math.max(1, Math.round(image.naturalWidth * scale));
  const height = Math.max(1, Math.round(image.naturalHeight * scale));

  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    return Promise.reject(new Error('canvas_context_unavailable'));
  }

  // Fond blanc avant le dessin : le JPEG ne porte pas de canal alpha, un PNG
  // transparent deviendrait sinon noir aux endroits transparents.
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, width, height);
  ctx.drawImage(image, 0, 0, width, height);

  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob ? resolve(blob) : reject(new Error('canvas_encode_failed'))),
      'image/jpeg',
      quality,
    );
  });
}
