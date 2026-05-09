import Link from 'next/link';
import type { MyCandidature } from '@/lib/api/client';

interface DossierPhotoCardProps {
  candidature: MyCandidature;
}

export function DossierPhotoCard({ candidature }: DossierPhotoCardProps): JSX.Element {
  const hasPhoto = candidature.has_photo === true;
  const isLocked = candidature.statut !== 'postulant';

  return (
    <section
      aria-labelledby="photo-heading"
      className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
      data-testid="dossier-photo-card"
    >
      <h2 id="photo-heading" className="font-heading text-lg font-bold text-[#6B2FA0]">
        Photo d'identité
      </h2>

      <div className="mt-4 grid items-start gap-5 md:grid-cols-[120px_1fr]">
        <div
          aria-hidden={!hasPhoto}
          data-testid="dossier-photo-thumbnail"
          className="relative aspect-square w-[120px] overflow-hidden rounded-md border border-gray-200 bg-gray-50"
        >
          {hasPhoto && candidature.photo_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={candidature.photo_url}
              alt=""
              className="h-full w-full object-cover"
            />
          ) : (
            <div className="flex h-full w-full items-center justify-center text-[10px] uppercase tracking-wide text-gray-600">
              Aucune photo
            </div>
          )}
        </div>

        <div className="flex flex-col gap-3 text-sm text-[#666]">
          {hasPhoto ? (
            <p>
              Photo enregistrée. Elle apparaîtra sur votre récépissé et sera vérifiée au dépôt
              physique de votre dossier.
            </p>
          ) : (
            <p>
              Ajoutez une photo d'identité récente, bien éclairée, fond neutre. Format JPG ou PNG,
              minimum 200×200 px, max 2 Mo.
            </p>
          )}

          {!isLocked ? (
            <Link
              href="/dossier/photo"
              data-testid="dossier-photo-cta"
              className="inline-flex w-fit items-center rounded-md bg-[#6B2FA0] px-4 py-2 text-sm font-medium text-white hover:bg-[#9B59B6] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0] focus:ring-offset-2"
            >
              {hasPhoto ? 'Modifier ma photo' : 'Ajouter ma photo'}
            </Link>
          ) : (
            <p
              role="status"
              data-testid="dossier-photo-locked"
              className="inline-flex w-fit items-center rounded-md border border-gray-200 bg-gray-50 px-4 py-2 text-xs text-[#666]"
            >
              Photo verrouillée — dossier déposé.
            </p>
          )}
        </div>
      </div>
    </section>
  );
}
