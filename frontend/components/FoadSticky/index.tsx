import { GraduationCap } from 'lucide-react';

/**
 * Bouton sticky vers la plateforme FOAD (cf. spec module 1 PR O).
 *
 * Visible sur toutes les pages publiques. Ouverture target=_blank vers
 * foad.pssfp.net.
 */
export function FoadSticky(): JSX.Element {
  return (
    <a
      href={process.env.NEXT_PUBLIC_FOAD_URL ?? 'https://foad.pssfp.net'}
      target="_blank"
      rel="noopener noreferrer"
      data-testid="foad-sticky"
      aria-label="Accéder à la plateforme FOAD (nouvelle fenêtre)"
      className="fixed bottom-4 right-4 z-30 hidden items-center gap-2 rounded-full bg-[#6B2FA0] px-5 py-3 text-sm font-medium text-white shadow-lg hover:bg-[#9B59B6] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 md:inline-flex"
    >
      <GraduationCap size={16} aria-hidden="true" />
      Accéder à la FOAD
    </a>
  );
}
