import Link from 'next/link';
import { CheckCircle2 } from 'lucide-react';

/**
 * Tableau des 12 exigences CAMES (Conseil Africain et Malgache pour
 * l'Enseignement Supérieur) avec liens vers les pages institutionnelles
 * démontrant la satisfaction de chaque exigence.
 *
 * Cf. spec module 1 PR K + spec institutionnelle CDC §11 (CAMES).
 *
 * Composant statique (pas de fetch dynamique) — la liste des 12 exigences
 * est stable et issue du référentiel CAMES.
 */
const REQUIREMENTS = [
  { id: 1, label: 'Mission et identité institutionnelle', linkSlug: 'a-propos/presentation' },
  { id: 2, label: 'Gouvernance et organes de pilotage', linkSlug: 'a-propos/comite-pilotage' },
  { id: 3, label: 'Programmes pédagogiques et curriculum', linkSlug: 'formations' },
  { id: 4, label: 'Corps enseignant et qualifications', linkSlug: 'vie-academique/corps-enseignant' },
  { id: 5, label: 'Étudiants et conditions d\'admission', linkSlug: 'formations/admission' },
  { id: 6, label: 'Recherche et activités scientifiques', linkSlug: 'a-propos/convention-tripartite' },
  { id: 7, label: 'Coopération nationale et internationale', linkSlug: 'a-propos/partenaires' },
  { id: 8, label: 'Infrastructure et équipements', linkSlug: 'a-propos/infrastructure' },
  { id: 9, label: 'Ressources documentaires (bibliothèque)', linkSlug: 'a-propos/infrastructure' },
  { id: 10, label: 'Système d\'information et numérique pédagogique', linkSlug: 'a-propos/organigramme' },
  { id: 11, label: 'Démarche qualité et auto-évaluation', linkSlug: 'a-propos/comite-pilotage' },
  { id: 12, label: 'Insertion professionnelle des diplômés', linkSlug: 'vie-academique/promotions' },
] as const;

export function CamesGrid(): JSX.Element {
  return (
    <section
      aria-labelledby="cames-heading"
      data-testid="cames-grid"
      className="rounded-xl border border-[#D4AF6A]/40 bg-white p-6 md:p-8"
    >
      <h2
        id="cames-heading"
        className="font-heading text-2xl font-bold text-[#4A2E67]"
      >
        Les 12 exigences CAMES
      </h2>
      <p className="mt-2 text-sm text-[#555]">
        Tableau récapitulatif des exigences du Conseil Africain et Malgache pour l'Enseignement
        Supérieur. Chaque exigence est rattachée à la page du PSSFP qui en démontre la
        satisfaction.
      </p>

      <ul className="mt-6 grid gap-2 md:grid-cols-2">
        {REQUIREMENTS.map((req) => (
          <li key={req.id}>
            <Link
              href={`/${req.linkSlug}`}
              data-testid={`cames-req-${req.id}`}
              className="group flex items-start gap-3 rounded-md border border-[#F4EFFA] bg-white p-3 transition-colors hover:border-[#D4AF6A] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
            >
              <CheckCircle2
                size={18}
                aria-hidden="true"
                className="mt-0.5 shrink-0 text-[#D4AF6A]"
              />
              <span className="grow text-sm text-[#333] group-hover:text-[#4A2E67]">
                <span className="font-semibold">Exigence {String(req.id).padStart(2, '0')}.</span>{' '}
                {req.label}
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
