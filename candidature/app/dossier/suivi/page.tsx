import Link from 'next/link';
import { redirect } from 'next/navigation';
import { StatusTimeline } from '@/components/StatusTimeline';
import { WithdrawDialog } from '@/components/WithdrawDialog';
import { getMyCandidature } from '@/lib/api/client';
import { clearCandidatToken, getCandidatToken } from '@/lib/auth/session';

export const metadata = {
  title: 'Suivi de ma candidature',
};

export default async function SuiviPage(): Promise<JSX.Element> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const result = await getMyCandidature(token);

  if (!result.ok) {
    if (result.status === 401) {
      await clearCandidatToken();
      redirect('/login?reason=session_expired');
    }
    return (
      <div className="mx-auto max-w-3xl px-6 py-16">
        <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">Suivi</h1>
        <div role="alert" className="mt-6 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-700">
          Erreur de chargement : {result.message}
        </div>
      </div>
    );
  }

  const candidature = result.data;
  const canWithdraw =
    candidature.withdrawn_at === null
    && candidature.statut !== 'accepte'
    && candidature.statut !== 'refuse';

  return (
    <div className="mx-auto max-w-3xl px-6 py-10 md:py-16">
      <header className="mb-8 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">Suivi de ma candidature</h1>
          <p className="mt-1 text-sm text-[#666]">
            Numéro de dossier <span className="font-mono">{candidature.numero_dossier}</span>
          </p>
        </div>
        <Link href="/dossier" className="text-sm text-[#4A2E67] underline">
          Retour au dossier
        </Link>
      </header>

      <section className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h2 className="mb-6 font-heading text-lg font-bold text-[#4A2E67]">Étapes</h2>
        <StatusTimeline candidature={candidature} />
      </section>

      {canWithdraw && (
        <section className="mt-8 rounded-lg border border-red-200 bg-red-50 p-6">
          <h2 className="font-heading text-base font-bold text-red-700">Zone sensible</h2>
          <p className="mt-2 text-sm text-red-900">
            Vous pouvez retirer définitivement votre candidature pour la campagne courante.
            Cette action est irréversible mais vous pourrez postuler à une campagne future.
          </p>
          <div className="mt-4">
            <WithdrawDialog />
          </div>
        </section>
      )}
    </div>
  );
}
