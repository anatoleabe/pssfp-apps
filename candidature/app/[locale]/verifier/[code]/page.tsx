import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { BadgeCheck, ShieldAlert } from 'lucide-react';
import { verifierDocument } from '@/lib/api/client';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('verification');

  return { title: t('title'), robots: { index: false, follow: false } };
}

/** Vérification publique d'un document officiel (RG-059) : type, numéro, date, titulaire, statut. */
export default async function VerifierPage({ params }: { params: { code: string } }): Promise<JSX.Element> {
  const t = await getTranslations('verification');
  const code = params.code.toUpperCase();
  const resultat = /^[A-Z0-9]{12}$/.test(code) ? await verifierDocument(code) : null;
  const document = resultat?.ok ? resultat.data : null;
  const authentique = document?.statut === 'authentique';

  return (
    <main id="main" className="mx-auto max-w-2xl px-6 py-12">
      <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">{t('title')}</h1>
      <section aria-live="polite" className="mt-8 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft" data-testid="verification">
        {document === null ? (
          <p className="flex items-start gap-3 text-[#3C3C3C]">
            <ShieldAlert size={24} aria-hidden="true" className="shrink-0 text-[#8A1C1C]" />
            {t('inconnu')}
          </p>
        ) : (
          <>
            <p className={`flex items-center gap-3 text-xl font-bold ${authentique ? 'text-[#1F6B3A]' : 'text-[#8A1C1C]'}`}>
              {authentique ? <BadgeCheck size={28} aria-hidden="true" /> : <ShieldAlert size={28} aria-hidden="true" />}
              {authentique ? t('authentique') : t('revoque', { date: document.revoque_le ?? '' })}
            </p>
            <dl className="mt-6 grid gap-3 text-[#3C3C3C] sm:grid-cols-2">
              <div><dt className="text-xs uppercase text-[#5C5C5C]">{t('type')}</dt><dd className="font-semibold">{document.libelle}</dd></div>
              <div><dt className="text-xs uppercase text-[#5C5C5C]">{t('numero')}</dt><dd className="font-mono font-semibold">{document.numero}</dd></div>
              <div><dt className="text-xs uppercase text-[#5C5C5C]">{t('titulaire')}</dt><dd className="font-semibold">{document.titulaire}</dd></div>
              <div><dt className="text-xs uppercase text-[#5C5C5C]">{t('date')}</dt><dd className="font-semibold">{document.delivre_le ? new Date(document.delivre_le).toLocaleDateString('fr-FR') : '—'}</dd></div>
            </dl>
            <p className="mt-6 text-sm text-[#5C5C5C]">{t('aide')}</p>
          </>
        )}
      </section>
    </main>
  );
}
