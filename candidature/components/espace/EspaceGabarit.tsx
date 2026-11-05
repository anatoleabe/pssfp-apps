import { getTranslations } from 'next-intl/server';
import type { ReactNode } from 'react';
import type { MaScolarite, MyCandidature } from '@/lib/api/client';
import { EspaceNav, type Rubrique } from './EspaceNav';

interface EspaceGabaritProps {
  candidature: MyCandidature;
  scolarite: MaScolarite;
  /** Rubrique affichée : son nom devient le titre de la page, sauf à l'accueil. */
  rubrique?: Rubrique;
  children: ReactNode;
}

/** En-tête de l'auditeur (identité, matricule, promotion) et barre des rubriques, commun à tout l'espace. */
export async function EspaceGabarit({ candidature, scolarite, rubrique = 'accueil', children }: EspaceGabaritProps): Promise<JSX.Element> {
  const t = await getTranslations('dossier.page');
  const te = await getTranslations('espace');
  const ts = await getTranslations('dossier.scolarite');
  const initiales = `${(candidature.prenom ?? '').trim().charAt(0)}${(candidature.nom ?? '').trim().charAt(0)}`.toUpperCase() || '?';
  const niveau = scolarite.niveau ? ts(`niveaux.${scolarite.niveau}`) : null;
  // Chaque morceau seulement s'il existe : jamais de séparateur vide à la lecture vocale.
  const reperes = [
    scolarite.matricule,
    scolarite.promotion_numero !== null ? ts('promotion', { numero: scolarite.promotion_numero }) : null,
    niveau,
    scolarite.annee_academique,
  ].filter((r): r is string => Boolean(r));
  const salut = (
    <>
      {t('greeting')} <span className="text-[#4A2E67]">{candidature.prenom || t('applicantFallback')}</span>
    </>
  );

  return (
    <div className="relative isolate min-h-[60vh]">
      <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-72 bg-[#FAF7F2]" />
      <div className="mx-auto max-w-5xl px-6 py-10 md:py-16">
        <header className="mb-8 flex flex-wrap items-center gap-5">
          <span
            aria-hidden="true"
            className="inline-flex h-16 w-16 items-center justify-center rounded-full bg-[#4A2E67] font-heading text-xl font-bold text-white shadow-pssfp-elevated ring-4 ring-white"
          >
            {initiales}
          </span>
          <div className="flex-1">
            <p className="pssfp-eyebrow">{te('eyebrow')}</p>
            {rubrique === 'accueil' ? (
              <h1 className="mt-1 font-heading text-2xl font-bold text-[#1A1A1A] md:text-3xl">{salut}</h1>
            ) : (
              <>
                <p className="mt-1 text-base font-semibold text-[#1A1A1A]">{salut}</p>
                <h1 className="mt-1 font-heading text-2xl font-bold text-[#1A1A1A] md:text-3xl">{te(`nav.${rubrique}`)}</h1>
              </>
            )}
            <p data-testid="entete-auditeur" className="mt-1 text-sm font-semibold text-[#4A2E67]">{reperes.join(' · ')}</p>
          </div>
        </header>
        <EspaceNav />
        <div className="grid gap-6">{children}</div>
      </div>
    </div>
  );
}
