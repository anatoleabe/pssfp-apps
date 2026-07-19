import { Check, Circle, Clock, AlertCircle } from 'lucide-react';
import type { MyCandidature } from '@/lib/api/client';
import { formatDateFr } from '@/lib/format/date';

interface Etape {
  done: boolean;
  label: string;
  current?: boolean;
}

export function DossierEtapesRestantes({ candidature }: { candidature: MyCandidature }): JSX.Element {
  const etapes: Etape[] = [
    { done: true, label: 'Inscription en ligne complétée' },
    {
      done: candidature.submitted_at !== null,
      label: candidature.submitted_at
        ? `Dossier soumis le ${formatDateFr(candidature.submitted_at)}`
        : 'Soumettre votre dossier en ligne',
    },
    {
      done: candidature.frais_paye === true,
      label: 'Régler les frais 50 000 FCFA en agence CREMINCAM',
    },
    {
      done: candidature.documents.length > 0,
      label:
        'Ajouter vos pièces justificatives en ligne (facultatif) ou les apporter au bureau de la scolarité — Yaoundé-Messa, porte 231',
    },
    {
      done: false,
      label: 'Apporter au bureau de la scolarité le récépissé de versement CREMINCAM',
    },
    {
      done: candidature.decided_at !== null,
      label: candidature.decided_at
        ? `Décision rendue le ${formatDateFr(candidature.decided_at)}`
        : 'Attendre la décision du comité d\'admission',
    },
  ];

  // Marquer la première étape non-faite comme "current"
  const firstNotDone = etapes.findIndex((e) => !e.done);
  if (firstNotDone >= 0) {
    const target = etapes[firstNotDone];
    if (target) {
      etapes[firstNotDone] = { done: target.done, label: target.label, current: true };
    }
  }

  const closesAt = candidature.campagne?.closes_at;
  const totalSteps = etapes.length;
  const doneSteps = etapes.filter((e) => e.done).length;
  const progress = Math.round((doneSteps / totalSteps) * 100);

  return (
    <section
      aria-labelledby="etapes-heading"
      className="relative overflow-hidden rounded-pssfp-card border border-[#F4EFFA] bg-[#FAF7F2] p-6 md:p-7"
    >
      {/* Halo décoratif */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-[#D4AF6A]/20 opacity-25 blur-3xl"
      />

      <div className="relative flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="pssfp-eyebrow">Parcours</p>
          <h2
            id="etapes-heading"
            className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]"
          >
            Étapes restantes
          </h2>
        </div>
        <div className="rounded-full border border-[#F4EFFA] bg-white px-3 py-1 text-xs font-semibold text-[#4A2E67]">
          {doneSteps} / {totalSteps} ·{' '}
          <span className="text-[#4A2E67]">{progress}%</span>
        </div>
      </div>

      {/* Barre de progression */}
      <div className="relative mt-5 h-2 w-full overflow-hidden rounded-full bg-white/80">
        <div
          aria-hidden="true"
          className="h-full rounded-full bg-[#4A2E67] transition-all duration-700 ease-pssfp-out-expo"
          style={{ width: `${progress}%` }}
        />
      </div>

      <ol
        data-testid="dossier-etapes"
        className="relative mt-6 space-y-3 text-sm"
      >
        {etapes.map((e, idx) => (
          <li
            key={idx}
            className={`flex items-start gap-3 rounded-xl border p-3 transition-all ${
              e.done
                ? 'border-emerald-200 bg-emerald-50/60'
                : e.current
                ? 'border-[#5C3A7E]/40 bg-white shadow-pssfp-soft'
                : 'border-transparent bg-white/40'
            }`}
          >
            <span
              aria-hidden="true"
              className={`mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${
                e.done
                  ? 'bg-emerald-500 text-white'
                  : e.current
                  ? 'bg-[#4A2E67] text-white shadow-pssfp-glow-or animate-pssfp-pulse-prune'
                  : 'border-2 border-gray-300 bg-white text-gray-400'
              }`}
            >
              {e.done ? <Check size={14} /> : e.current ? <Clock size={14} /> : <Circle size={10} />}
            </span>
            <span
              className={`leading-relaxed ${
                e.done
                  ? 'text-emerald-900'
                  : e.current
                  ? 'font-semibold text-[#1A1A1A]'
                  : 'text-[#555]'
              }`}
            >
              {e.label}
            </span>
          </li>
        ))}
      </ol>

      {closesAt && (
        <p className="relative mt-5 inline-flex items-center gap-2 rounded-pssfp-button bg-amber-50 px-3 py-2 text-xs text-amber-900">
          <AlertCircle size={12} aria-hidden="true" />
          Date limite de candidature : <strong>{formatDateFr(closesAt)}</strong>
        </p>
      )}
    </section>
  );
}
