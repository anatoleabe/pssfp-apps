'use client';

import { useState, useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { withdrawDossierAction } from '@/app/dossier/actions';

export function WithdrawDialog(): JSX.Element {
  const [open, setOpen] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();
  const router = useRouter();

  const handleWithdraw = (): void => {
    if (!confirmed) return;
    setServerError(null);
    startTransition(async () => {
      const r = await withdrawDossierAction();
      if (r.ok) {
        setOpen(false);
        router.refresh();
        return;
      }
      setServerError(r.message ?? 'Erreur lors du retrait.');
    });
  };

  return (
    <>
      <button
        type="button"
        data-testid="withdraw-trigger"
        onClick={() => setOpen(true)}
        className="inline-flex h-11 items-center rounded-md border border-red-300 px-4 text-sm font-medium text-red-700 hover:bg-red-50"
      >
        Retirer ma candidature
      </button>

      {open && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="withdraw-title"
          data-testid="withdraw-dialog"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        >
          <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h2 id="withdraw-title" className="font-heading text-lg font-bold text-red-700">
              Retirer définitivement ma candidature ?
            </h2>
            <p className="mt-3 text-sm text-[#666]">
              Cette action est <strong>irréversible</strong>. Vous ne pourrez plus modifier ni
              soumettre votre dossier pour la campagne en cours. Vous pourrez en revanche
              postuler à une campagne future.
            </p>

            <label className="mt-4 flex items-start gap-3 text-sm">
              <input
                type="checkbox"
                data-testid="withdraw-confirm"
                checked={confirmed}
                onChange={(e) => setConfirmed(e.target.checked)}
                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500"
              />
              <span>Je confirme vouloir retirer ma candidature.</span>
            </label>

            {serverError && (
              <div role="alert" className="mt-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                {serverError}
              </div>
            )}

            <div className="mt-6 flex items-center justify-end gap-3">
              <button
                type="button"
                onClick={() => {
                  setOpen(false);
                  setConfirmed(false);
                  setServerError(null);
                }}
                className="rounded-md border border-gray-300 px-4 py-2 text-sm text-[#333]"
              >
                Annuler
              </button>
              <button
                type="button"
                data-testid="withdraw-submit"
                disabled={!confirmed || pending}
                onClick={handleWithdraw}
                className="inline-flex h-10 items-center rounded-md bg-red-600 px-4 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-gray-300"
              >
                {pending ? 'Retrait…' : 'Confirmer le retrait'}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
