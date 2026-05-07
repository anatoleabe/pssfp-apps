import { getTranslations } from 'next-intl/server';
import { LoginFormPlaceholder } from '@/components/LoginFormPlaceholder';

export default async function LoginPage() {
  const t = await getTranslations('login');
  return (
    <div className="mx-auto max-w-md px-6 py-16">
      <h1 className="font-heading text-3xl font-bold text-[#6B2FA0]">
        {t('title')}
      </h1>
      <p className="mt-2 text-base text-[#666666]">{t('subtitle')}</p>

      <LoginFormPlaceholder
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

      <p
        role="note"
        className="mt-6 rounded-md border border-dashed border-gray-300 bg-[#F5F5F5] p-4 text-xs text-[#666666]"
      >
        {t('todoNotice')}
      </p>
    </div>
  );
}
