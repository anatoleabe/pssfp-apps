import { getTranslations } from 'next-intl/server';
import { LoginForm } from '@/components/LoginForm';
import { getPays } from '@/lib/api/client';
import { FALLBACK_PAYS } from '@/lib/api/fallbacks';

export const metadata = {
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
