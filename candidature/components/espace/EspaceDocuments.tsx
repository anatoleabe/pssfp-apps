import { useTranslations } from 'next-intl';
import { ArrowRight, FileDown, FileText } from 'lucide-react';
import type { ReactNode } from 'react';
import type { MyCandidature } from '@/lib/api/client';

const LIEN =
  'group flex min-h-14 items-center gap-3 rounded-pssfp-button border border-[#F4EFFA] bg-white px-5 py-3 font-semibold text-[#333] transition-colors duration-200 hover:border-[#D4AF6A] hover:text-[#4A2E67] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2';

function Document({ href, testId, icone, nouvelOnglet, children }: { href: string; testId: string; icone: ReactNode; nouvelOnglet: string; children: ReactNode }): JSX.Element {
  return (
    <li>
      <a href={href} target="_blank" rel="noopener noreferrer" data-testid={testId} className={LIEN}>
        <span aria-hidden="true" className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#F4EFFA] text-[#4A2E67]">{icone}</span>
        <span>
          {children}
          <span className="sr-only"> ({nouvelOnglet})</span>
        </span>
        <ArrowRight size={18} aria-hidden="true" className="ml-auto shrink-0" />
      </a>
    </li>
  );
}

/** Tous les documents de l'auditeur au même endroit ; les routes /dossier/* relaient le jeton httpOnly. */
export function EspaceDocuments({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const t = useTranslations('espace.documents');
  const resultats = candidature.campagne?.communique_resultats_url ?? null;

  return (
    <section aria-labelledby="documents-heading" data-testid="espace-documents" className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7">
      <p className="pssfp-eyebrow">{t('eyebrow')}</p>
      <h2 id="documents-heading" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">{t('title')}</h2>
      <ul className="mt-6 grid gap-3">
        {candidature.lettre_admission_available && (
          <Document href="/dossier/lettre-admission" testId="doc-lettre" icone={<FileDown size={18} />} nouvelOnglet={t('newTab')}>{t('lettre')}</Document>
        )}
        <Document href="/dossier/releve" testId="doc-releve" icone={<FileDown size={18} />} nouvelOnglet={t('newTab')}>{t('releve')}</Document>
        {candidature.recipisse_available && (
          <Document href="/dossier/recipisse" testId="doc-recipisse" icone={<FileText size={18} />} nouvelOnglet={t('newTab')}>{t('recipisse')}</Document>
        )}
        {resultats && (
          <Document href={resultats} testId="doc-resultats" icone={<FileText size={18} />} nouvelOnglet={t('newTab')}>{t('resultats')}</Document>
        )}
      </ul>
    </section>
  );
}
