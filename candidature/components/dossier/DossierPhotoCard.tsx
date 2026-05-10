import Link from 'next/link';
import { Camera, ImagePlus, Lock, ArrowRight } from 'lucide-react';
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
      className="rounded-pssfp-card border border-[#EDE7F6] bg-white p-6 shadow-pssfp-soft md:p-7"
      data-testid="dossier-photo-card"
    >
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="pssfp-eyebrow">Identité visuelle</p>
          <h2
            id="photo-heading"
            className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A0A2E]"
          >
            Photo d&apos;identité
          </h2>
        </div>
        <span
          aria-hidden="true"
          className={`inline-flex h-9 w-9 items-center justify-center rounded-xl ${
            hasPhoto ? 'bg-emerald-100 text-emerald-600' : 'bg-[#EDE7F6] text-[#6B2FA0]'
          }`}
        >
          <Camera size={18} />
        </span>
      </div>

      <div className="mt-5 grid items-start gap-5 sm:grid-cols-[120px_1fr]">
        <div
          aria-hidden={!hasPhoto}
          data-testid="dossier-photo-thumbnail"
          className="relative aspect-square w-[120px] overflow-hidden rounded-pssfp-card border border-[#EDE7F6] bg-gradient-lavande-blanc shadow-pssfp-soft"
        >
          {hasPhoto && candidature.photo_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={candidature.photo_url}
              alt=""
              className="h-full w-full object-cover"
            />
          ) : (
            <div className="flex h-full w-full flex-col items-center justify-center gap-2 text-[10px] uppercase tracking-wide text-[#9B59B6]">
              <ImagePlus size={28} aria-hidden="true" />
              Aucune photo
            </div>
          )}
        </div>

        <div className="flex flex-col gap-4 text-sm leading-relaxed text-[#555]">
          {hasPhoto ? (
            <p>
              Photo enregistrée. Elle apparaîtra sur votre récépissé et sera vérifiée au dépôt
              physique de votre dossier.
            </p>
          ) : (
            <p>
              Ajoutez une photo d&apos;identité récente, bien éclairée, fond neutre. Format JPG ou
              PNG, minimum 200×200 px, max 2 Mo.
            </p>
          )}

          {!isLocked ? (
            <Link
              href="/dossier/photo"
              data-testid="dossier-photo-cta"
              className="group inline-flex w-fit items-center gap-2 rounded-pssfp-button bg-gradient-violet px-4 py-2.5 text-sm font-semibold text-white shadow-pssfp-elevated transition-all duration-200 hover:-translate-y-0.5 hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
            >
              {hasPhoto ? 'Modifier ma photo' : 'Ajouter ma photo'}
              <ArrowRight
                size={14}
                aria-hidden="true"
                className="transition-transform duration-200 group-hover:translate-x-0.5"
              />
            </Link>
          ) : (
            <p
              role="status"
              data-testid="dossier-photo-locked"
              className="inline-flex w-fit items-center gap-2 rounded-pssfp-button border border-[#EDE7F6] bg-[#FAF7FF] px-3 py-2 text-xs text-[#666]"
            >
              <Lock size={12} aria-hidden="true" />
              Photo verrouillée — dossier déposé.
            </p>
          )}
        </div>
      </div>
    </section>
  );
}
