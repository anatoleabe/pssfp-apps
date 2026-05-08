import { redirect } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { WizardContainer } from '@/components/wizard/WizardContainer';
import { getPays, getSpecialites } from '@/lib/api/client';
import { getCandidatToken } from '@/lib/auth/session';
import { submitInscription } from './actions';
import type { Pays, Specialite } from '@/lib/api/types';

export const metadata = {
  title: 'Inscription candidat',
  description: 'Créez votre compte candidat PSSFP en 4 étapes guidées.',
};

export default async function InscriptionPage(): Promise<JSX.Element> {
  const existingToken = await getCandidatToken();
  if (existingToken) {
    redirect('/dossier');
  }

  const t = await getTranslations('inscription');

  const [paysResult, specialitesResult] = await Promise.all([getPays(), getSpecialites()]);

  const pays: Pays[] = paysResult.ok ? paysResult.data : [];
  const specialites: Specialite[] = specialitesResult.ok ? specialitesResult.data : [];

  return (
    <div className="mx-auto max-w-3xl px-6 py-10 md:py-16">
      <header className="mb-8 space-y-3">
        <h1 className="font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl">
          {t('title')}
        </h1>
        <p className="text-[#666]">{t('subtitle')}</p>
      </header>

      <WizardContainer
        pays={pays}
        specialites={specialites}
        submitAction={submitInscription}
      />
    </div>
  );
}
