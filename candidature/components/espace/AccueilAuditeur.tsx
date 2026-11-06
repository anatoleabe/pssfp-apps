import { useLocale, useTranslations } from 'next-intl';
import { ArrowRight, Info } from 'lucide-react';
import { Link } from '@/navigation';
import type { MaScolarite, MyCandidature } from '@/lib/api/client';
import { prochainPalier } from '@/lib/espace/charger';
import { formatDateLong } from '@/lib/format/date';
import { formatFcfa } from '@/lib/format/montant';

const LIEN = 'mt-4 inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-[#4A2E67] underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2';

/** Synthèse de l'accueil : argent, accès, dernier message, et le statut s'il n'est pas « Actif ». */
export function AccueilAuditeur({ scolarite, candidature }: { scolarite: MaScolarite; candidature: MyCandidature }): JSX.Element {
  const t = useTranslations('espace');
  const locale = useLocale();
  const palier = prochainPalier(scolarite);
  const echeance = palier ? (palier.echeance_texte ?? (palier.echeance ? formatDateLong(palier.echeance, locale) : null)) : null;
  const dernier = candidature.messages?.[0] ?? null;

  return (
    <>
      {scolarite.statut && scolarite.statut !== 'actif' && t.has(`statut.${scolarite.statut}`) && (
        <p data-testid="statut-auditeur" className="flex items-start gap-3 rounded-pssfp-card border border-amber-200 bg-amber-50/90 p-5 text-sm text-amber-900">
          <Info size={18} aria-hidden="true" className="mt-0.5 shrink-0" />
          {t(`statut.${scolarite.statut}`)}
        </p>
      )}
      <section aria-labelledby="accueil-heading" data-testid="espace-accueil" className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7">
        <p className="pssfp-eyebrow">{t('accueil.eyebrow')}</p>
        <h2 id="accueil-heading" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">{t('accueil.title')}</h2>
        <div className="mt-6 grid gap-4 md:grid-cols-3">
          <div className="rounded-pssfp-card bg-[#FAF7F2] p-5">
            <h3 className="font-ui text-xs uppercase tracking-[0.14em] text-[#5C5C5C]">{t('accueil.reste')}</h3>
            {(scolarite.reste ?? 0) > 0 ? (
              <>
                <p className="mt-1 text-2xl font-bold tabular-nums text-[#0F3A4A]">{formatFcfa(scolarite.reste ?? 0, locale)}</p>
                {echeance && <p className="mt-1 text-sm text-[#3C3C3C]">{t('accueil.prochaineEcheance', { echeance })}</p>}
              </>
            ) : (
              <p className="mt-1 text-base font-semibold text-emerald-800">{t('accueil.toutRegle')}</p>
            )}
            <Link href="/dossier/scolarite" className={LIEN}>{t('accueil.voirScolarite')}<ArrowRight size={14} aria-hidden="true" /></Link>
          </div>
          <div className="rounded-pssfp-card bg-[#FAF7F2] p-5">
            <h3 className="font-ui text-xs uppercase tracking-[0.14em] text-[#5C5C5C]">{t('accueil.acces')}</h3>
            <p className={`mt-1 text-base font-semibold ${scolarite.acces.etat === 'disponible' ? 'text-emerald-800' : 'text-[#3C3C3C]'}`}>
              {t(`accueil.accesEtat.${scolarite.acces.etat}`)}
            </p>
            <Link href="/dossier/acces" className={LIEN}>{t('accueil.voirAcces')}<ArrowRight size={14} aria-hidden="true" /></Link>
          </div>
          <div className="rounded-pssfp-card bg-[#FAF7F2] p-5">
            <h3 className="font-ui text-xs uppercase tracking-[0.14em] text-[#5C5C5C]">{t('accueil.dernierMessage')}</h3>
            {dernier ? (
              <p className="mt-1 line-clamp-3 text-sm text-[#3C3C3C]">{dernier.sujet || dernier.message}</p>
            ) : (
              <p className="mt-1 text-sm text-[#5C5C5C]">{t('accueil.aucunMessage')}</p>
            )}
            <Link href="/dossier/messages" className={LIEN}>{t('accueil.voirMessages')}<ArrowRight size={14} aria-hidden="true" /></Link>
          </div>
        </div>
      </section>
    </>
  );
}
