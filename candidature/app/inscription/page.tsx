import { redirect } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { WizardContainer } from '@/components/wizard/WizardContainer';
import { getDiplomes, getPays, getSpecialites, getUniversites } from '@/lib/api/client';
import {
  FALLBACK_DIPLOMES,
  FALLBACK_PAYS,
  FALLBACK_SPECIALITES,
  FALLBACK_UNIVERSITES,
} from '@/lib/api/fallbacks';
import { getCandidatToken } from '@/lib/auth/session';
import { submitInscription } from './actions';
import type { Diplome, Pays, Specialite, UniversitePays } from '@/lib/api/types';

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

  const [paysResult, specialitesResult, diplomesResult, universitesResult] = await Promise.all([
    getPays(),
    getSpecialites(),
    getDiplomes(),
    getUniversites(),
  ]);

  // Fallback hardcoded utilisé quand le backend est temporairement
  // indisponible (incident, déploiement, ou CI candidature sans backend live).
  // Permet au wizard de rester opérationnel en mode dégradé.
  const pays: Pays[] = paysResult.ok && paysResult.data.length > 0
    ? paysResult.data
    : [...FALLBACK_PAYS];
  const specialites: Specialite[] = specialitesResult.ok && specialitesResult.data.length > 0
    ? specialitesResult.data
    : [...FALLBACK_SPECIALITES];
  const diplomes: Diplome[] = diplomesResult.ok && diplomesResult.data.length > 0
    ? diplomesResult.data
    : [...FALLBACK_DIPLOMES];
  const universites: UniversitePays[] = universitesResult.ok && universitesResult.data.length > 0
    ? universitesResult.data
    : [...FALLBACK_UNIVERSITES];

  return (
    <div className="relative isolate min-h-[60vh]">
      {/* Background décoratif */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-72 bg-[#FAF7F2]"
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-32 top-12 -z-10 h-72 w-72 rounded-full bg-[#D4AF6A]/20 opacity-20 blur-3xl"
      />

      <div className="mx-auto max-w-3xl px-6 py-10 md:py-16">
        <header className="mb-10 max-w-2xl space-y-3 text-center md:text-left">
          <p className="pssfp-eyebrow inline-block">{t('eyebrow')}</p>
          <h1 className="font-heading text-pssfp-h1 font-bold text-[#1A1A1A]">
            {t('title')}
          </h1>
          <p className="pssfp-lead">{t('subtitle')}</p>
        </header>

        <WizardContainer
          pays={pays}
          specialites={specialites}
          diplomes={diplomes}
          universites={universites}
          submitAction={submitInscription}
        />
      </div>
    </div>
  );
}
