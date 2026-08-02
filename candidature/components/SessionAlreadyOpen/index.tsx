import { LogOut, UserCheck } from 'lucide-react';
import { getTranslations } from 'next-intl/server';

import { logoutAction } from '@/app/[locale]/dossier/actions';
import { Link } from '@/navigation';

interface SessionAlreadyOpenProps {
  /** Numéro du dossier ouvert, quand l'API a pu le fournir. */
  numero?: string | null;
}

/**
 * Écran affiché quand un visiteur demande /login, /inscription ou /forgot-pin
 * alors qu'une session candidat est déjà ouverte (audit A-36).
 *
 * Le portail redirigeait auparavant vers /dossier sans un mot. Le cas est
 * pourtant courant — poste partagé dans un service, cybercafé, un proche qui
 * veut candidater à son tour — et l'utilisateur ne pouvait que constater que
 * « le lien ne marche pas », sans deviner qu'il devait d'abord se déconnecter.
 */
export async function SessionAlreadyOpen({ numero }: SessionAlreadyOpenProps): Promise<JSX.Element> {
  const t = await getTranslations('sessionAlreadyOpen');

  return (
    <div className="mx-auto max-w-xl px-6 py-16">
      <section
        aria-labelledby="session-open-heading"
        data-testid="session-already-open"
        className="rounded-2xl border border-[#4A2E67]/20 bg-[var(--pssfp-ivoire)] p-6 shadow-pssfp-soft sm:p-8"
      >
        <span
          aria-hidden="true"
          className="mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-[#4A2E67] text-white"
        >
          <UserCheck size={22} />
        </span>

        <h1 id="session-open-heading" className="font-heading text-2xl font-bold text-[#4A2E67]">
          {t('title')}
        </h1>

        <p className="mt-3 text-sm leading-relaxed text-[#3C3C3C]">
          {numero ? t('bodyWithNumber', { numero }) : t('body')}
        </p>

        <p className="mt-3 text-sm leading-relaxed text-[#3C3C3C]">{t('howTo')}</p>

        <div className="mt-6 flex flex-wrap gap-3">
          <Link
            href="/dossier"
            data-testid="session-open-continue"
            className="inline-flex items-center gap-2 rounded-pssfp-button bg-[#4A2E67] px-5 py-3 text-sm font-medium text-white shadow-pssfp-elevated transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
          >
            {t('continue')}
          </Link>

          <form action={logoutAction}>
            <button
              type="submit"
              data-testid="session-open-logout"
              className="inline-flex items-center gap-2 rounded-pssfp-button border border-[#4A2E67] bg-white px-5 py-3 text-sm font-medium text-[#4A2E67] transition-all duration-200 hover:bg-[#F4EFFA] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
            >
              <LogOut size={16} aria-hidden="true" />
              {t('logout')}
            </button>
          </form>
        </div>
      </section>
    </div>
  );
}
