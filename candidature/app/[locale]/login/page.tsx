import { headers } from 'next/headers';
import { SessionAlreadyOpen } from '@/components/SessionAlreadyOpen';
import { getCandidatToken } from '@/lib/auth/session';
import { getTranslations } from 'next-intl/server';
import { LoginForm } from '@/components/LoginForm';
import { getPays, getMyCandidature } from '@/lib/api/client';
import { FALLBACK_PAYS } from '@/lib/api/fallbacks';

export const metadata = {
  // Page d'authentification ou espace candidat : hors index (audit A-32).
  robots: { index: false, follow: false },
  title: 'Connexion candidat',
};

interface LoginPageProps {
  searchParams: Promise<{ phone?: string; reason?: string }>;
}

const REASON_MESSAGES: Record<string, string> = {
  logged_out: 'Vous avez été déconnecté(e).',
  session_expired: 'Votre session a expiré. Reconnectez-vous.',
  service_unavailable: 'Le service de connexion est momentanément indisponible. Réessayez dans quelques instants.',
  pin_reset: 'Votre PIN a été réinitialisé. Connectez-vous avec votre nouveau PIN.',
};

export default async function LoginPage({ searchParams }: LoginPageProps): Promise<JSX.Element> {
  // Session déjà ouverte : on l'annonce au lieu de rediriger en silence
  // (audit A-36). Le numéro de dossier est affiché quand l'API le fournit.
  const sessionValid = (await headers()).get('x-candidat-session-valid') === '1';
  if (sessionValid) {
    const token = await getCandidatToken();
    const existing = token ? await getMyCandidature(token) : null;

    return (
      <SessionAlreadyOpen numero={existing?.ok ? existing.data.numero_dossier : null} />
    );
  }

  const t = await getTranslations('login');
  const paysResult = await getPays();
  const pays = paysResult.ok && paysResult.data.length > 0 ? paysResult.data : [...FALLBACK_PAYS];
  const { phone, reason } = await searchParams;
  const reasonMessage = reason ? REASON_MESSAGES[reason] ?? null : null;

  return (
    <div className="mx-auto max-w-md px-6 py-16">
      <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">{t('title')}</h1>
      <p className="mt-2 text-base text-[#666666]">{t('subtitle')}</p>

      <LoginForm
        initialPhone={phone}
        reasonMessage={reasonMessage}
        pays={pays}
        labels={{
          phoneLabel: t('phoneLabel'),
          phonePlaceholder: t('phonePlaceholder'),
          phoneHelp: t('phoneHelp'),
          pinLabel: t('pinLabel'),
          pinHelp: t('pinHelp'),
          submit: t('submit'),
          forgotPin: t('forgotPin'),
        }}
      />
    </div>
  );
}
