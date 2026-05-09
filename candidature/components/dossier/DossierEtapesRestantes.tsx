import type { MyCandidature } from '@/lib/api/client';
import { formatDateFr } from '@/lib/format/date';

interface Etape {
  done: boolean;
  label: string;
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
      done: false, // V1 : pas de tracking dépôt physique côté candidat
      label: 'Apporter au PSSFP : récépissé CREMINCAM + dossier physique (CV, copies diplômes, lettre, photo, pièce d\'identité, relevés)',
    },
    {
      done: candidature.decided_at !== null,
      label: candidature.decided_at
        ? `Décision rendue le ${formatDateFr(candidature.decided_at)}`
        : 'Attendre la décision du comité d\'admission',
    },
  ];

  const closesAt = candidature.campagne?.closes_at;

  return (
    <section
      aria-labelledby="etapes-heading"
      className="rounded-lg border border-[#EDE7F6] bg-[#FAF7FF] p-6"
    >
      <h2 id="etapes-heading" className="font-heading text-lg font-bold text-[#6B2FA0]">
        Étapes restantes pour finaliser votre candidature
      </h2>

      <ul
        data-testid="dossier-etapes"
        className="mt-4 space-y-2 text-sm text-[#333]"
      >
        {etapes.map((e, idx) => (
          <li key={idx} className="flex items-start gap-3">
            <span
              aria-hidden
              className={`mt-0.5 inline-flex h-5 w-5 flex-shrink-0 items-center justify-center rounded border text-xs ${
                e.done
                  ? 'border-emerald-500 bg-emerald-100 text-emerald-700'
                  : 'border-gray-300 bg-white text-gray-400'
              }`}
            >
              {e.done ? '✓' : ''}
            </span>
            <span className={e.done ? 'text-[#333]' : 'text-[#555]'}>{e.label}</span>
          </li>
        ))}
      </ul>

      {closesAt && (
        <p className="mt-4 text-xs text-gray-500">
          Date limite de candidature : <strong>{formatDateFr(closesAt)}</strong>.
        </p>
      )}
    </section>
  );
}
