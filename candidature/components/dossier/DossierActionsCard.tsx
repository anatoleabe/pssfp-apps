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
        <li>
          <Link
            href="/dossier/suivi"
            data-testid="dossier-link-suivi"
            className="inline-flex items-center gap-2 rounded-md border border-[#6B2FA0] px-4 py-2 font-medium text-[#6B2FA0] hover:bg-[#EDE7F6]"
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
              className="inline-flex items-center gap-2 rounded-md border border-gray-300 px-4 py-2 text-[#333] hover:border-[#6B2FA0]"
            >
              📄 Télécharger mon récépissé PDF
            </a>
          </li>
        )}
      </ul>
    </section>
  );
}
