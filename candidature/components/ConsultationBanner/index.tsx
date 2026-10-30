import { getTranslations } from 'next-intl/server';
import { Eye, LogOut } from 'lucide-react';
import { logoutAction } from '@/app/[locale]/dossier/actions';

/**
 * Bandeau affiché quand un agent du PSSFP consulte l'espace d'un candidat
 * depuis l'administration. Il reste visible sur toutes les pages : l'agent ne
 * doit jamais oublier qu'il regarde l'espace de quelqu'un d'autre.
 */
export async function ConsultationBanner(): Promise<JSX.Element> {
  const t = await getTranslations('consultation');

  return (
    <div
      role="status"
      data-testid="consultation-banner"
      className="border-b border-[#B8913F] bg-[#D4AF6A] text-[#1A1A1A]"
    >
      <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-6 py-3 sm:flex-nowrap">
        <p className="flex min-w-0 items-start gap-2.5 text-sm">
          <Eye size={18} aria-hidden="true" className="mt-0.5 shrink-0" />
          <span>
            <strong className="font-semibold">{t('title')}</strong> {t('body')}
          </span>
        </p>
        <form action={logoutAction} className="shrink-0">
          <button
            type="submit"
            data-testid="consultation-quit"
            className="inline-flex h-10 items-center gap-1.5 rounded-pssfp-button bg-[#1A1A1A] px-4 text-sm font-semibold text-white transition-colors duration-200 hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1A1A1A] focus-visible:ring-offset-2 focus-visible:ring-offset-[#D4AF6A]"
          >
            <LogOut size={14} aria-hidden="true" />
            {t('quit')}
          </button>
        </form>
      </div>
    </div>
  );
}
