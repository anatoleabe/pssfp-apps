import { useTranslations } from 'next-intl';
import { ArrowRight, FileDown, FileText } from 'lucide-react';
import type { ReactNode } from 'react';
import type { MyCandidature } from '@/lib/api/client';

const LIEN_CLASSES =
  'group flex min-h-14 items-center gap-3 rounded-pssfp-button border border-[#F4EFFA] bg-white px-5 py-3 font-semibold text-[#333] transition-all duration-200 ease-pssfp-out-expo hover:-translate-y-0.5 hover:border-[#D4AF6A] hover:text-[#4A2E67] hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2';

interface DocumentProps {
  href: string;
  testId: string;
  icone: ReactNode;
  nouvelOnglet: string;
  children: ReactNode;
}

function Document({ href, testId, icone, nouvelOnglet, children }: DocumentProps): JSX.Element {
  return (
    <li>
      <a href={href} target="_blank" rel="noopener noreferrer" data-testid={testId} className={LIEN_CLASSES}>
        <span aria-hidden="true" className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#F4EFFA] text-[#4A2E67]">
          {icone}
        </span>
        <span>
          {children}
          <span className="sr-only"> ({nouvelOnglet})</span>
        </span>
        <ArrowRight size={18} aria-hidden="true" className="ml-auto shrink-0 transition-transform duration-200 group-hover:translate-x-0.5" />
      </a>
    </li>
  );
}

/**
 * Documents officiels de l'auditeur : sa lettre d'admission signée, visible sans
 * condition de paiement, et le communiqué des résultats de la campagne.
 */
export function DossierDocumentsCard({ candidature }: { candidature: MyCandidature }): JSX.Element | null {
  const t = useTranslations('dossier.documents');
  const resultatsUrl = candidature.campagne?.communique_resultats_url ?? null;

  if (!candidature.lettre_admission_available && !resultatsUrl) {
    return null;
  }

  return (
    <section
      aria-labelledby="documents-heading"
      data-testid="dossier-documents"
      className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7"
    >
      <p className="pssfp-eyebrow">{t('eyebrow')}</p>
      <h2 id="documents-heading" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">
        {t('title')}
      </h2>
      <ul className="mt-6 grid gap-3">
        {candidature.lettre_admission_available && (
          // Route Next.js et non l'API : le jeton est un cookie httpOnly (cf. /dossier/recipisse).
          <Document href="/dossier/lettre-admission" testId="lettre-admission-download" icone={<FileDown size={18} />} nouvelOnglet={t('newTab')}>
            {t('lettre')}
          </Document>
        )}
        {resultatsUrl && (
          <Document href={resultatsUrl} testId="resultats-download" icone={<FileText size={18} />} nouvelOnglet={t('newTab')}>
            {t('resultats')}
          </Document>
        )}
      </ul>
    </section>
  );
}
