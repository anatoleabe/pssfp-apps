'use client';

import { useId, useState } from 'react';
import { useTranslations } from 'next-intl';
import { BadgeCheck, FileSearch, ShieldAlert } from 'lucide-react';
import { verifierContenu, type ContenuVerifie } from '@/app/[locale]/verifier/[code]/actions';

const TAILLE_MAX_OCTETS = 20 * 1024 * 1024;

type Etat = ContenuVerifie | 'attente' | 'calcul' | 'trop_lourd' | 'pas_pdf';

async function empreinteSha256(fichier: File): Promise<string> {
  const condense = await crypto.subtle.digest('SHA-256', await fichier.arrayBuffer());

  return Array.from(new Uint8Array(condense), (octet) => octet.toString(16).padStart(2, '0')).join('');
}

/**
 * Contrôle du contenu d'un document officiel : le vérificateur choisit le PDF qu'on lui a remis,
 * son empreinte est calculée ici, dans le navigateur, puis comparée à celle du document délivré.
 */
export function VerificationContenu({ code }: { code: string }): JSX.Element {
  const t = useTranslations('verification.contenu');
  const champ = useId();
  const [etat, setEtat] = useState<Etat>('attente');

  async function verifier(fichier: File | undefined): Promise<void> {
    if (!fichier) {
      setEtat('attente');
      return;
    }
    if (fichier.type !== 'application/pdf' && !fichier.name.toLowerCase().endsWith('.pdf')) {
      setEtat('pas_pdf');
      return;
    }
    if (fichier.size > TAILLE_MAX_OCTETS) {
      setEtat('trop_lourd');
      return;
    }
    setEtat('calcul');
    try {
      setEtat(await verifierContenu(code, await empreinteSha256(fichier)));
    } catch {
      setEtat('indisponible');
    }
  }

  const conforme = etat === 'conforme';
  const alerte = etat === 'different';

  return (
    <section className="mt-8 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft" data-testid="verification-contenu">
      <h2 className="flex items-center gap-2 font-heading text-xl font-bold text-[#4A2E67]">
        <FileSearch size={22} aria-hidden="true" />
        {t('title')}
      </h2>
      <p className="mt-2 text-sm text-[#3C3C3C]">{t('intro')}</p>
      <label htmlFor={champ} className="mt-4 block text-sm font-semibold text-[#3C3C3C]">
        {t('label')}
      </label>
      <input
        id={champ}
        type="file"
        accept="application/pdf,.pdf"
        aria-describedby={`${champ}-aide`}
        onChange={(event) => void verifier(event.target.files?.[0])}
        className="mt-2 block w-full text-sm text-[#3C3C3C] file:mr-4 file:rounded-md file:border-0 file:bg-[#4A2E67] file:px-4 file:py-2 file:font-semibold file:text-white hover:file:bg-[#3A2452] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#4A2E67]"
      />
      <p id={`${champ}-aide`} className="mt-2 text-xs text-[#5C5C5C]">
        {t('confidentialite')}
      </p>
      <p
        role="status"
        aria-live="polite"
        data-testid="verification-contenu-resultat"
        className={`mt-4 flex min-h-[1.5rem] items-start gap-2 font-semibold ${conforme ? 'text-[#1F6B3A]' : alerte ? 'text-[#8A1C1C]' : 'text-[#3C3C3C]'}`}
      >
        {conforme ? <BadgeCheck size={22} aria-hidden="true" className="shrink-0" /> : null}
        {alerte ? <ShieldAlert size={22} aria-hidden="true" className="shrink-0" /> : null}
        {etat === 'attente' ? '' : t(`etat.${etat}`)}
      </p>
    </section>
  );
}
