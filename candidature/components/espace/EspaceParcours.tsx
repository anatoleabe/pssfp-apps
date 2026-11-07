import { useTranslations } from 'next-intl';
import { GraduationCap } from 'lucide-react';
import type { MonParcours, ParcoursUe } from '@/lib/api/client';

const CARTE = 'min-w-0 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7';

/** Maquette de l'auditeur, semestre par semestre ; rien n'est montré avant la validation par la scolarité. */
export function EspaceParcours({ parcours }: { parcours: MonParcours }): JSX.Element {
  const t = useTranslations('espace.parcours');

  return (
    <div className="grid min-w-0 gap-6" data-testid="espace-parcours">
      <section aria-labelledby="parcours-heading" className={CARTE}>
        <p className="pssfp-eyebrow">{t('eyebrow')}</p>
        <h2 id="parcours-heading" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">
          {parcours.parcours ? t('title', { niveau: parcours.niveau, libelle: parcours.parcours.libelle }) : t('eyebrow')}
        </h2>
        <p className="mt-2 text-sm text-[#3C3C3C]">
          {t('annee', { annee: parcours.annee_academique })}
          {parcours.maquette_validee && <> · <strong>{t('creditsTotal', { credits: parcours.credits_total })}</strong></>}
        </p>
        {!parcours.parcours && <p className="mt-4 text-[#3C3C3C]" data-testid="parcours-sans">{t('sansParcours')}</p>}
        {parcours.parcours && !parcours.maquette_validee && (
          <p className="mt-4 text-[#3C3C3C]" data-testid="parcours-preparation">{t('enPreparation')}</p>
        )}
        {parcours.maquette_validee && !parcours.inscrit && <p className="mt-4 text-[#3C3C3C]">{t('nonInscrit')}</p>}
      </section>

      {parcours.semestres.map((semestre) => (
        <section key={semestre.numero} aria-labelledby={`semestre-${semestre.numero}`} className={CARTE} data-testid={`semestre-${semestre.numero}`}>
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <h3 id={`semestre-${semestre.numero}`} className="font-heading text-xl font-bold text-[#4A2E67]">
              {t('semestre', { numero: semestre.numero })}
            </h3>
            <span className="text-sm font-semibold text-[#3C3C3C]">{t('creditsSemestre', { credits: semestre.credits })}</span>
          </div>
          <ul className="mt-5 grid gap-4">
            {semestre.ues.map((ue) => (
              <Ue key={ue.code} ue={ue} />
            ))}
          </ul>
        </section>
      ))}

      {parcours.semestres.length > 0 && <p className="text-xs text-[#5C5C5C]">{t('legende')}</p>}
    </div>
  );
}

function Ue({ ue }: { ue: ParcoursUe }): JSX.Element {
  const t = useTranslations('espace.parcours');
  const cellule = 'px-2 py-2 text-right tabular-nums';

  return (
    <li className="min-w-0 rounded-pssfp-button border border-[#F4EFFA]">
      <div className="flex flex-wrap items-start gap-3 bg-[#FAF7F2] px-4 py-3">
        <span aria-hidden="true" className="mt-0.5 hidden h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#F4EFFA] text-[#4A2E67] sm:inline-flex">
          <GraduationCap size={16} />
        </span>
        <div className="min-w-0 flex-1 basis-40">
          <p className="font-mono text-xs text-[#5C3A7E]">{ue.code}</p>
          <p className="font-semibold text-[#1A1A1A]">{ue.intitule}</p>
          {ue.partie === 'soutenance' && <p className="text-sm text-[#3C3C3C]">{t('soutenance')}</p>}
        </div>
        <span className="shrink-0 rounded-full bg-[#4A2E67] px-3 py-1 text-xs font-semibold text-white">{t('credits', { credits: ue.credits })}</span>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[34rem] table-fixed text-sm">
          <caption className="sr-only">{ue.intitule}</caption>
          <thead>
            <tr className="text-xs uppercase text-[#5C5C5C]">
              <th scope="col" className="px-4 py-2 text-left font-semibold">{t('colonnes.ecue')}</th>
              <th scope="col" className="w-14 px-2 py-2 text-right font-semibold">{t('colonnes.cm')}</th>
              <th scope="col" className="w-14 px-2 py-2 text-right font-semibold">{t('colonnes.td')}</th>
              <th scope="col" className="w-14 px-2 py-2 text-right font-semibold">{t('colonnes.tp')}</th>
              <th scope="col" className="w-14 px-2 py-2 text-right font-semibold">{t('colonnes.tpe')}</th>
              <th scope="col" className="w-24 px-4 py-2 text-right font-semibold">{t('colonnes.credits')}</th>
            </tr>
          </thead>
          <tbody>
            {ue.ecues.map((e) => (
              <tr key={e.code} className="border-t border-[#F4EFFA] text-[#3C3C3C]">
                <th scope="row" className="px-4 py-2 text-left font-normal">
                  <span className="block font-mono text-xs text-[#5C3A7E]">{e.code}</span>
                  {e.intitule}
                </th>
                <td className={cellule}>{e.heures_cm}</td>
                <td className={cellule}>{e.heures_td}</td>
                <td className={cellule}>{e.heures_tp}</td>
                <td className={cellule}>{e.heures_tpe}</td>
                <td className="px-4 py-2 text-right font-semibold tabular-nums">{e.credits}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </li>
  );
}
