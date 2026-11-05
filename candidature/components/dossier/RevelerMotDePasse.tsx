'use client';

import { useState, useTransition } from 'react';
import { useTranslations } from 'next-intl';
import { Eye, EyeOff } from 'lucide-react';
import { revelerAccesAction, type RevelerAccesResult } from '@/app/[locale]/dossier/actions';

const BOUTON =
  'inline-flex min-h-11 items-center gap-2 rounded-pssfp-button border border-[#4A2E67] px-4 py-2 text-sm font-semibold text-[#4A2E67] transition-colors duration-200 hover:bg-[#4A2E67] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 aria-disabled:cursor-wait';

/** Le mot de passe ne vit que dans l'état de ce composant, le temps de l'affichage. */
export function RevelerMotDePasse(): JSX.Element {
  const t = useTranslations('dossier.acces');
  const [motDePasse, setMotDePasse] = useState<string | null>(null);
  const [erreur, setErreur] = useState<Extract<RevelerAccesResult, { ok: false }>['erreur'] | null>(null);
  const [enCours, startTransition] = useTransition();

  const afficher = (): void => {
    // aria-disabled plutôt que disabled : un bouton désactivé perd le focus clavier.
    if (enCours) return;
    setErreur(null);
    startTransition(async () => {
      const r = await revelerAccesAction();
      if (r.ok) {
        setMotDePasse(r.motDePasse);
      } else {
        setErreur(r.erreur);
      }
    });
  };

  return (
    <div className="flex flex-wrap items-center gap-3">
      <output data-testid="mot-de-passe" className="font-mono text-lg font-semibold text-[#1A1A1A]">
        {motDePasse ?? <span className="text-sm font-normal text-[#5C5C5C]">{t('masque')}</span>}
      </output>
      {motDePasse === null ? (
        <button type="button" onClick={afficher} aria-disabled={enCours} aria-busy={enCours} className={BOUTON}>
          <Eye size={16} aria-hidden="true" />
          {enCours ? t('chargement') : t('afficher')}
        </button>
      ) : (
        <button type="button" onClick={() => setMotDePasse(null)} className={BOUTON}>
          <EyeOff size={16} aria-hidden="true" />
          {t('masquer')}
        </button>
      )}
      {erreur && (
        <p role="alert" className="w-full text-sm font-medium text-red-700">
          {erreur === 'consultation' ? t('consultation') : t(`erreurs.${erreur}`)}
        </p>
      )}
    </div>
  );
}
