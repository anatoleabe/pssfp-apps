import Link from 'next/link';
import { Pencil, ListChecks, FileDown, ArrowRight } from 'lucide-react';
import type { MyCandidature } from '@/lib/api/client';

export function DossierActionsCard({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const apiBase = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';
  const recipisseUrl = `${apiBase}/applications/me/recipisse`;

  return (
    <section
      aria-labelledby="actions-heading"
      className="rounded-pssfp-card border border-[#EDE7F6] bg-white p-6 shadow-pssfp-soft md:p-7"
    >
      <p className="pssfp-eyebrow">Actions disponibles</p>
      <h2
        id="actions-heading"
        className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A0A2E]"
      >
        Que souhaitez-vous faire&nbsp;?
      </h2>
      <ul className="mt-6 grid gap-3 sm:grid-cols-2">
        {candidature.statut === 'postulant' && candidature.withdrawn_at === null && (
          <li className="sm:col-span-2">
            <Link
              href="/dossier/edition"
              data-testid="dossier-link-edition"
              className="group relative flex h-14 items-center gap-3 overflow-hidden rounded-pssfp-button bg-gradient-violet-or px-5 text-base font-semibold text-white shadow-pssfp-elevated transition-all duration-200 ease-pssfp-out-expo hover:-translate-y-0.5 hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C9A227] focus-visible:ring-offset-2"
            >
              <span
                aria-hidden="true"
                className="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/25 to-transparent transition-transform duration-700 group-hover:translate-x-full"
              />
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
            className="group flex h-14 items-center gap-3 rounded-pssfp-button border border-[#EDE7F6] bg-white px-5 font-semibold text-[#6B2FA0] transition-all duration-200 ease-pssfp-out-expo hover:-translate-y-0.5 hover:border-[#6B2FA0] hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
          >
            <span aria-hidden="true" className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-[#EDE7F6] text-[#6B2FA0] transition-colors group-hover:bg-[#6B2FA0] group-hover:text-white">
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
              className="group flex h-14 items-center gap-3 rounded-pssfp-button border border-[#EDE7F6] bg-white px-5 font-semibold text-[#333] transition-all duration-200 ease-pssfp-out-expo hover:-translate-y-0.5 hover:border-[#C9A227] hover:text-[#C9A227] hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C9A227] focus-visible:ring-offset-2"
            >
              <span aria-hidden="true" className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-[#FFF6E0] text-[#C9A227] transition-colors group-hover:bg-[#C9A227] group-hover:text-white">
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
