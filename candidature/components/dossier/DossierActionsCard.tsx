import Link from 'next/link';
import { Pencil, ListChecks, FileDown, ArrowRight } from 'lucide-react';
import type { MyCandidature } from '@/lib/api/client';

export function DossierActionsCard({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const apiBase = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';
  const recipisseUrl = `${apiBase}/applications/me/recipisse`;

  return (
    <section
      aria-labelledby="actions-heading"
      className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7"
    >
      <p className="pssfp-eyebrow">Actions disponibles</p>
      <h2
        id="actions-heading"
        className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]"
      >
        Que souhaitez-vous faire&nbsp;?
      </h2>
      <ul className="mt-6 grid gap-3 sm:grid-cols-2">
        {candidature.statut === 'postulant' && candidature.withdrawn_at === null && (
          <li className="sm:col-span-2">
            <Link
              href="/dossier/edition"
              data-testid="dossier-link-edition"
              className="group relative flex h-14 items-center gap-3 overflow-hidden rounded-pssfp-button bg-[#4A2E67] px-5 text-base font-semibold text-white shadow-pssfp-elevated transition-all duration-200 ease-pssfp-out-expo hover:-translate-y-0.5 hover:bg-[#3A2452] hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A] focus-visible:ring-offset-2"
            >
              <span aria-hidden="true" className="relative inline-flex h-9 w-9 items-center justify-center rounded-xl bg-white/15 backdrop-blur-2xl">
                <Pencil size={18} />
              </span>
              <span className="relative">Modifier mon dossier</span>
              <ArrowRight
                size={18}
                aria-hidden="true"
                className="relative ml-auto transition-transform duration-200 group-hover:translate-x-0.5"
              />
            </Link>
          </li>
        )}
        <li>
          <Link
            href="/dossier/suivi"
            data-testid="dossier-link-suivi"
            className="group flex h-14 items-center gap-3 rounded-pssfp-button border border-[#F4EFFA] bg-white px-5 font-semibold text-[#4A2E67] transition-all duration-200 ease-pssfp-out-expo hover:-translate-y-0.5 hover:border-[#4A2E67] hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
          >
            <span aria-hidden="true" className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-[#F4EFFA] text-[#4A2E67] transition-colors group-hover:bg-[#4A2E67] group-hover:text-white">
              <ListChecks size={18} />
            </span>
            Voir le suivi
            <ArrowRight
              size={18}
              aria-hidden="true"
              className="ml-auto transition-transform duration-200 group-hover:translate-x-0.5"
            />
          </Link>
        </li>
        {candidature.recipisse_available && (
          <li>
            <a
              href={recipisseUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="group flex h-14 items-center gap-3 rounded-pssfp-button border border-[#F4EFFA] bg-white px-5 font-semibold text-[#333] transition-all duration-200 ease-pssfp-out-expo hover:-translate-y-0.5 hover:border-[#D4AF6A] hover:text-[#D4AF6A] hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A] focus-visible:ring-offset-2"
            >
              <span aria-hidden="true" className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-[#FFF6E0] text-[#D4AF6A] transition-colors group-hover:bg-[#D4AF6A] group-hover:text-white">
                <FileDown size={18} />
              </span>
              Récépissé PDF
              <ArrowRight
                size={18}
                aria-hidden="true"
                className="ml-auto transition-transform duration-200 group-hover:translate-x-0.5"
              />
            </a>
          </li>
        )}
      </ul>
    </section>
  );
}
