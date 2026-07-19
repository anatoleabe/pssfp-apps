import Link from 'next/link';
import { Paperclip, ArrowRight } from 'lucide-react';
import type { MyCandidature } from '@/lib/api/client';

interface DossierPiecesCardProps {
  candidature: MyCandidature;
}

export function DossierPiecesCard({ candidature }: DossierPiecesCardProps): JSX.Element {
  const count = candidature.documents.length;
  const isLocked = candidature.statut !== 'postulant';

  return (
    <section
      aria-labelledby="pieces-heading"
      className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7"
      data-testid="dossier-pieces-card"
    >
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="pssfp-eyebrow">Dossier de candidature</p>
          <h2
            id="pieces-heading"
            className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]"
          >
            Pièces justificatives
          </h2>
        </div>
        <span
          aria-hidden="true"
          className={`inline-flex h-9 w-9 items-center justify-center rounded-xl ${
            count > 0 ? 'bg-emerald-100 text-emerald-600' : 'bg-[#F4EFFA] text-[#4A2E67]'
          }`}
        >
          <Paperclip size={18} />
        </span>
      </div>

      <div className="mt-4 flex flex-col gap-4 text-sm leading-relaxed text-[#555]">
        <p>
          {count > 0
            ? `${count} pièce${count > 1 ? 's' : ''} déposée${count > 1 ? 's' : ''} en ligne.`
            : 'Diplôme, acte de naissance, relevés de notes, CV, lettre de motivation, attestation employeur.'}{' '}
          Facultatif : vous pouvez aussi les apporter directement au bureau de la scolarité
          (Yaoundé-Messa, porte 231).
        </p>

        {!isLocked ? (
          <Link
            href="/dossier/pieces"
            data-testid="dossier-pieces-cta"
            className="group inline-flex w-fit items-center gap-2 rounded-pssfp-button bg-gradient-prune px-4 py-2.5 text-sm font-semibold text-white shadow-pssfp-elevated transition-all duration-200 hover:-translate-y-0.5 hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
          >
            {count > 0 ? 'Gérer mes pièces' : 'Ajouter des pièces'}
            <ArrowRight
              size={14}
              aria-hidden="true"
              className="transition-transform duration-200 group-hover:translate-x-0.5"
            />
          </Link>
        ) : (
          <p
            role="status"
            data-testid="dossier-pieces-locked"
            className="inline-flex w-fit items-center gap-2 rounded-pssfp-button border border-[#F4EFFA] bg-[#FAF7FF] px-3 py-2 text-xs text-[#666]"
          >
            Pièces verrouillées — dossier déposé.
          </p>
        )}
      </div>
    </section>
  );
}
