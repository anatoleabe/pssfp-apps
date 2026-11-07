'use client';

import { useMemo, useState, type FormEvent } from 'react';
import { useTranslations } from 'next-intl';
import { useRouter } from '@/navigation';
import type { MesRequetes } from '@/lib/api/client';

const CHAMP = 'mt-1 block w-full min-h-11 rounded-pssfp-button border border-[#D9D2E3] bg-white px-3 py-2 text-[#1A1A1A] focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]';
const LIBELLE = 'block text-sm font-semibold text-[#1A1A1A]';

interface Accuse {
  numero: string;
  empreinte: string;
}

/** Dépôt d'une requête de note ou d'une demande de document, avec pièces (PDF, JPEG, PNG, 5 Mo). */
export function FormulaireRequete({ options }: { options: MesRequetes }): JSX.Element {
  const t = useTranslations('espace.requetes.formulaire');
  const router = useRouter();
  const [type, setType] = useState<'note' | 'document'>(options.cibles.length > 0 ? 'note' : 'document');
  const [cible, setCible] = useState<string>('');
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);
  const [accuse, setAccuse] = useState<Accuse | null>(null);
  const choisie = useMemo(() => options.cibles.find((c) => `${c.ecue}|${c.session}` === cible), [cible, options.cibles]);

  async function deposer(e: FormEvent<HTMLFormElement>): Promise<void> {
    e.preventDefault();
    setErreur(null);
    const form = new FormData(e.currentTarget);
    form.set('type', type);
    if (type === 'note' && choisie) {
      form.set('ecue', choisie.ecue);
      form.set('session', choisie.session);
    }
    form.delete('cible');
    setEnvoi(true);
    try {
      const r = await fetch('/dossier/requetes/deposer', { method: 'POST', body: form });
      const payload = (await r.json().catch(() => ({}))) as { message?: string; data?: Accuse };
      if (!r.ok || !payload.data) {
        setErreur(payload.message ?? t('erreur'));
        return;
      }
      setAccuse(payload.data);
      e.currentTarget?.reset();
      setCible('');
      router.refresh();
    } catch {
      setErreur(t('erreur'));
    } finally {
      setEnvoi(false);
    }
  }

  return (
    <form onSubmit={deposer} className="grid gap-5" data-testid="formulaire-requete" noValidate>
      {accuse && (
        <p role="status" className="rounded-pssfp-button border border-[#1F6B3A] bg-[#F0FAF3] px-4 py-3 text-sm text-[#1A1A1A]">
          {t('accuse', { numero: accuse.numero })}
          <span className="mt-1 block break-all font-mono text-xs text-[#3C3C3C]">{t('empreinte', { empreinte: accuse.empreinte })}</span>
        </p>
      )}
      {erreur && <p role="alert" className="rounded-pssfp-button border border-[#8A1C1C] bg-[#FDF2F2] px-4 py-3 text-sm text-[#8A1C1C]">{erreur}</p>}

      <fieldset>
        <legend className={LIBELLE}>{t('type')}</legend>
        <div className="mt-2 flex flex-wrap gap-4">
          <label className="inline-flex min-h-11 items-center gap-2">
            <input type="radio" name="type_choix" checked={type === 'note'} onChange={() => setType('note')} disabled={options.cibles.length === 0} />
            {t('typeNote')}
          </label>
          <label className="inline-flex min-h-11 items-center gap-2">
            <input type="radio" name="type_choix" checked={type === 'document'} onChange={() => setType('document')} />
            {t('typeDocument')}
          </label>
        </div>
        {options.cibles.length === 0 && <p className="mt-1 text-sm text-[#5C5C5C]">{t('aucuneCible')}</p>}
      </fieldset>

      {type === 'note' ? (
        <>
          <label className={LIBELLE}>
            {t('ecue')}
            <select name="cible" required value={cible} onChange={(e) => setCible(e.target.value)} className={CHAMP}>
              <option value="">{t('choisir')}</option>
              {options.cibles.map((c) => (
                <option key={`${c.ecue}|${c.session}`} value={`${c.ecue}|${c.session}`}>
                  {c.ecue} — {c.intitule} ({t(`session.${c.session}`)})
                </option>
              ))}
            </select>
          </label>
          <div className="grid gap-5 sm:grid-cols-2">
            <label className={LIBELLE}>
              {t('noteVisee')}
              <select name="note_visee" required className={CHAMP} disabled={!choisie}>
                {(choisie?.notes ?? []).map((n) => (
                  <option key={n} value={n}>{t(`notes.${n}`)}</option>
                ))}
              </select>
            </label>
            <label className={LIBELLE}>
              {t('motif')}
              <select name="motif" required className={CHAMP}>
                {Object.entries(options.motifs).map(([cle, libelle]) => (
                  <option key={cle} value={cle}>{libelle}</option>
                ))}
              </select>
            </label>
          </div>
        </>
      ) : (
        <label className={LIBELLE}>
          {t('document')}
          <select name="type_document" required className={CHAMP}>
            {Object.entries(options.documents).map(([cle, libelle]) => (
              <option key={cle} value={cle}>{libelle}</option>
            ))}
          </select>
        </label>
      )}

      <label className={LIBELLE}>
        {t('description')}
        <textarea name="description" required minLength={10} maxLength={2000} rows={4} className={CHAMP} />
      </label>
      <label className={LIBELLE}>
        {t('pieces')}
        <input type="file" name="pieces[]" multiple accept="application/pdf,image/jpeg,image/png" className="mt-1 block w-full text-sm" />
        <span className="mt-1 block text-xs font-normal text-[#5C5C5C]">{t('piecesAide')}</span>
      </label>

      <button type="submit" disabled={envoi || (type === 'note' && !choisie)}
        className="inline-flex min-h-11 w-fit items-center rounded-pssfp-button bg-[#4A2E67] px-6 font-semibold text-white transition-colors hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 disabled:opacity-50">
        {envoi ? t('envoi') : t('deposer')}
      </button>
    </form>
  );
}
