import { redirect } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { LoginForm } from '@/components/LoginForm';
import { getCandidatToken } from '@/lib/auth/session';

export const metadata = {
  title: 'Connexion candidat',
};

interface LoginPageProps {
  searchParams: Promise<{ phone?: string; reason?: string }>;
}

const REASON_MESSAGES: Record<string, string> = {
  logged_out: 'Vous avez été déconnecté(e).',
  session_expired: 'Votre session a expiré. Reconnectez-vous.',
  pin_reset: 'Votre PIN a été réinitialisé. Connectez-vous avec votre nouveau PIN.',
};

export default async function LoginPage({ searchParams }: LoginPageProps): Promise<JSX.Element> {
  const existingToken = await getCandidatToken();
  if (existingToken) {
    redirect('/dossier');
  }

  const t = await getTranslations('login');
  const { phone, reason } = await searchParams;
  const reasonMessage = reason ? REASON_MESSAGES[reason] ?? null : null;

  return (
    <div className="mx-auto max-w-md px-6 py-16">
      <h1 className="font-heading text-3xl font-bold text-[#6B2FA0]">{t('title')}</h1>
      <p className="mt-2 text-base text-[#666666]">{t('subtitle')}</p>

      <LoginForm
        initialPhone={phone}
        reasonMessage={reasonMessage}
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
