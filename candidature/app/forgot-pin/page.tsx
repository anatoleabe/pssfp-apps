import { redirect } from 'next/navigation';
import { ForgotPinWizard } from '@/components/ForgotPinWizard';
import { getCandidatToken } from '@/lib/auth/session';

export const metadata = {
  title: 'PIN oublié',
};

export default async function ForgotPinPage(): Promise<JSX.Element> {
  const existingToken = await getCandidatToken();
  if (existingToken) {
    redirect('/dossier');
  }

  return (
    <div className="mx-auto max-w-md px-6 py-16">
      <h1 className="font-heading text-3xl font-bold text-[#6B2FA0]">PIN oublié</h1>
      <p className="mt-2 text-base text-[#666]">
        Récupérez l'accès à votre dossier en trois étapes guidées.
      </p>

      <div className="mt-8">
        <ForgotPinWizard />
      </div>
    </div>
  );
}
