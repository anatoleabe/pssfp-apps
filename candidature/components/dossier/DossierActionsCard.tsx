import Link from 'next/link';
import type { MyCandidature } from '@/lib/api/client';

export function DossierActionsCard({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const apiBase = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';
  const recipisseUrl = `${apiBase}/applications/me/recipisse`;

  return (
    <section
      aria-labelledby="actions-heading"
      className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
    >
      <h2 id="actions-heading" className="font-heading text-lg font-bold text-[#6B2FA0]">
        Actions sur mon dossier
      </h2>
      <ul className="mt-4 space-y-3 text-sm">
        {candidature.statut === 'postulant' && candidature.withdrawn_at === null && (
          <li>
            <Link
              href="/dossier/edition"
              data-testid="dossier-link-edition"
              className="inline-flex h-11 items-center gap-2 rounded-md bg-[#6B2FA0] px-4 font-medium text-white hover:bg-[#9B59B6] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
            >
              <span aria-hidden="true">✏️</span>
              Modifier mon dossier
            </Link>
          </li>
        )}
        <li>
          <Link
            href="/dossier/suivi"
            data-testid="dossier-link-suivi"
            className="inline-flex h-11 items-center gap-2 rounded-md border border-[#6B2FA0] px-4 font-medium text-[#6B2FA0] hover:bg-[#EDE7F6] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
          >
            Voir le suivi de ma candidature →
          </Link>
        </li>
        {candidature.recipisse_available && (
          <li>
            <a
              href={recipisseUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex h-11 items-center gap-2 rounded-md border border-gray-300 px-4 text-[#333] hover:border-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
            >
              <span aria-hidden="true">📄</span>
              Télécharger mon récépissé PDF
            </a>
          </li>
        )}
      </ul>
    </section>
  );
}
