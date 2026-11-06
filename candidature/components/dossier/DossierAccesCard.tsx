import { useLocale, useTranslations } from 'next-intl';
import { AlertCircle, ArrowUpRight, Download, KeyRound } from 'lucide-react';
import type { MaScolarite } from '@/lib/api/client';
import { formatFcfa } from '@/lib/format/montant';
import { RevelerMotDePasse } from './RevelerMotDePasse';

export type ErreurLettreAcces = 'non_prete' | 'indisponible' | 'limite' | 'echec';

interface DossierAccesCardProps {
  scolarite: MaScolarite;
  consultation: boolean;
  /** Retour de /dossier/lettre-acces quand le PDF n'a pas pu être servi. */
  erreurLettre?: ErreurLettreAcces | null;
}

const BOUTON_SECONDAIRE =
  'inline-flex min-h-11 items-center gap-2 rounded-pssfp-button border border-[#4A2E67] px-5 py-2.5 text-sm font-semibold text-[#4A2E67] transition-colors duration-200 hover:bg-[#4A2E67] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2';

/**
 * Accès eCampus de l'auditeur. Le mot de passe n'est pas dans la page : il est
 * demandé au clic, et l'API revérifie le solde de l'inscription.
 */
export function DossierAccesCard({ scolarite, consultation, erreurLettre = null }: DossierAccesCardProps): JSX.Element {
  const t = useTranslations('dossier.acces');
  const locale = useLocale();
  const { etat } = scolarite.acces;
  // Lettre PDF : l'API la déclare disponible (accès ouvert, mot de passe connu, date des lettres saisie) ; jamais en consultation.
  const lettreDisponible = scolarite.acces.lettre_disponible === true && !consultation;

  return (
    <section
      aria-labelledby="acces-heading"
      data-testid="dossier-acces"
      data-etat={etat}
      className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7"
    >
      <div className="flex items-start gap-4">
        <span aria-hidden="true" className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#0F3A4A] text-white">
          <KeyRound size={20} />
        </span>
        <div>
          <p className="pssfp-eyebrow">{t('eyebrow')}</p>
          <h2 id="acces-heading" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">
            {t('title')}
          </h2>
        </div>
      </div>

      {etat === 'bloque' && (
        <p className="mt-5 rounded-pssfp-card border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-900">
          {t('bloque', { reste: formatFcfa(scolarite.acces.reste_inscription ?? 0, locale) })}
        </p>
      )}

      {etat === 'en_preparation' && (
        <p className="mt-5 rounded-pssfp-card border border-[#A592BD]/40 bg-[#FAF7FF] p-4 text-sm text-[#3A2452]">{t('preparation')}</p>
      )}

      {etat === 'disponible' && (
        <>
          <dl className="mt-5 grid gap-4 sm:grid-cols-2">
            <div>
              <dt className="font-ui text-xs uppercase tracking-[0.14em] text-[#5C5C5C]">{t('identifiant')}</dt>
              <dd className="mt-1 font-mono text-lg font-semibold text-[#1A1A1A]">{scolarite.identifiant}</dd>
            </div>
            <div>
              <dt className="font-ui text-xs uppercase tracking-[0.14em] text-[#5C5C5C]">{t('motDePasse')}</dt>
              <dd className="mt-1">
                {scolarite.acces.mot_de_passe_connu === false ? (
                  <p data-testid="mot-de-passe-habituel" className="text-sm text-[#3C3C3C]">{t('motDePasseHabituel')}</p>
                ) : consultation ? (
                  <p className="text-sm text-[#5C5C5C]">{t('consultation')}</p>
                ) : (
                  <RevelerMotDePasse />
                )}
              </dd>
            </div>
          </dl>
          {erreurLettre && (
            <p role="alert" className="mt-5 flex items-start gap-2 rounded-pssfp-card border border-red-200 bg-red-50/90 p-4 text-sm font-medium text-red-900">
              <AlertCircle size={18} aria-hidden="true" className="mt-0.5 shrink-0 text-red-600" />
              {t(`lettreErreurs.${erreurLettre}`)}
            </p>
          )}
          <div className="mt-6 flex flex-wrap gap-3">
            {scolarite.acces.foad_url && (
              <a
                href={scolarite.acces.foad_url}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex min-h-11 items-center gap-2 rounded-pssfp-button bg-[#4A2E67] px-5 py-2.5 text-sm font-semibold text-white shadow-pssfp-elevated transition-colors duration-200 hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
              >
                {t('ouvrir')}
                <span className="sr-only"> ({t('newTab')})</span>
                <ArrowUpRight size={16} aria-hidden="true" />
              </a>
            )}
            {lettreDisponible && (
              // Lien simple : la route relaie le jeton httpOnly et renvoie le PDF en pièce jointe.
              <a href="/dossier/lettre-acces" data-testid="lettre-acces" className={BOUTON_SECONDAIRE}>
                <Download size={16} aria-hidden="true" />
                {t('telecharger')}
              </a>
            )}
          </div>
          <p className="mt-4 text-xs text-[#5C5C5C]">{t('conseil')}</p>
        </>
      )}
    </section>
  );
}
