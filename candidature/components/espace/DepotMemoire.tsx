'use client';

import { useState, type FormEvent } from 'react';
import { useTranslations } from 'next-intl';
import { useRouter } from '@/navigation';

/** Dépôt d'un livrable du mémoire (PDF, 10 Mo), relayé par le Route Handler. */
export function DepotMemoire({ etape }: { etape: string }): JSX.Element {
  const t = useTranslations('espace.memoire.depot');
  const router = useRouter();
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);
  const [empreinte, setEmpreinte] = useState<string | null>(null);

  async function deposer(e: FormEvent<HTMLFormElement>): Promise<void> {
    e.preventDefault();
    setErreur(null);
    const formulaire = e.currentTarget;
    setEnvoi(true);
    try {
      const r = await fetch(`/dossier/memoire/deposer/${etape}`, { method: 'POST', body: new FormData(formulaire) });
      const payload = (await r.json().catch(() => ({}))) as { message?: string; data?: { empreinte: string } };
      if (!r.ok || !payload.data) {
        setErreur(payload.message ?? t('erreur'));
        return;
      }
      setEmpreinte(payload.data.empreinte);
      formulaire.reset();
      router.refresh();
    } catch {
      setErreur(t('erreur'));
    } finally {
      setEnvoi(false);
    }
  }

  return (
    <form onSubmit={deposer} className="mt-3 grid gap-2" data-testid={`depot-${etape}`}>
      {empreinte && (
        <p role="status" className="rounded-pssfp-button border border-[#1F6B3A] bg-[#F0FAF3] px-3 py-2 text-sm text-[#1A1A1A]">
          {t('accuse')}
          <span className="mt-1 block break-all font-mono text-xs text-[#3C3C3C]">{t('empreinte', { empreinte })}</span>
        </p>
      )}
      {erreur && <p role="alert" className="rounded-pssfp-button border border-[#8A1C1C] bg-[#FDF2F2] px-3 py-2 text-sm text-[#8A1C1C]">{erreur}</p>}
      <label className="block text-sm font-semibold text-[#1A1A1A]">
        {t('fichier')}
        <input type="file" name="fichier" required accept="application/pdf" className="mt-1 block w-full text-sm font-normal" />
        <span className="mt-1 block text-xs font-normal text-[#5C5C5C]">{t('aide')}</span>
      </label>
      <button type="submit" disabled={envoi}
        className="inline-flex min-h-11 w-fit items-center rounded-pssfp-button bg-[#4A2E67] px-5 font-semibold text-white transition-colors hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 disabled:opacity-50">
        {envoi ? t('envoi') : t('deposer')}
      </button>
    </form>
  );
}
