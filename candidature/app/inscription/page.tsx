import { redirect } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { WizardContainer } from '@/components/wizard/WizardContainer';
import { getPays, getSpecialites } from '@/lib/api/client';
import { FALLBACK_PAYS, FALLBACK_SPECIALITES } from '@/lib/api/fallbacks';
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

  // Fallback hardcoded utilisé quand le backend est temporairement
  // indisponible (incident, déploiement, ou CI candidature sans backend live).
  // Permet au wizard de rester opérationnel en mode dégradé.
  const pays: Pays[] = paysResult.ok && paysResult.data.length > 0
    ? paysResult.data
    : [...FALLBACK_PAYS];
  const specialites: Specialite[] = specialitesResult.ok && specialitesResult.data.length > 0
    ? specialitesResult.data
    : [...FALLBACK_SPECIALITES];

  return (
    <div className="relative isolate min-h-[60vh]">
      {/* Background décoratif */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-72 bg-gradient-lavande-blanc"
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-32 top-12 -z-10 h-72 w-72 rounded-full opacity-20 blur-3xl"
        style={{ background: 'radial-gradient(circle, #C9A227 0%, transparent 70%)' }}
      />

      <div className="mx-auto max-w-3xl px-6 py-10 md:py-16">
        <header className="mb-10 max-w-2xl space-y-3 text-center md:text-left">
          <p className="pssfp-eyebrow inline-block">Promotion 14 · Campagne 2026</p>
          <h1 className="font-heading text-pssfp-h1 font-bold text-[#1A0A2E]">
            {t('title')}
          </h1>
          <p className="pssfp-lead">{t('subtitle')}</p>
        </header>

        <WizardContainer
          pays={pays}
          specialites={specialites}
          submitAction={submitInscription}
        />
      </div>
    </div>
  );
}
