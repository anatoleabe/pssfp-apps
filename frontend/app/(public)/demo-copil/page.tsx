import Link from 'next/link';
import type { Metadata } from 'next';
import {
  ArrowRight,
  CheckCircle2,
  GraduationCap,
  Users,
  Building2,
  Image as ImageIcon,
  FileText,
  Newspaper,
  Sparkles,
} from 'lucide-react';

/**
 * /demo-copil — page interne de récapitulatif de la démo COPIL
 * (cf. spec sprint S5 PR AA).
 *
 * URL non listée dans le sitemap, exclue de robots.txt.
 * Sert de tour guidé pour la présentation au Pr. BASAHAG et au COPIL.
 */
export const metadata: Metadata = {
  title: 'Démo COPIL — Sprint S5',
  description: 'Récapitulatif des livrables Sprint S5 pour la présentation au Comité de Pilotage.',
  robots: { index: false, follow: false },
};

interface PrStatus {
  id: string;
  title: string;
  description: string;
  highlights: string[];
  link?: { href: string; label: string };
  Icon: typeof Sparkles;
}

const PRS: ReadonlyArray<PrStatus> = [
  {
    id: 'V',
    title: 'PR V — Bibliothèque média + import MinIO',
    description:
      "67 assets institutionnels importés (logos, photos, documents) avec génération automatique de variantes WebP thumb/medium/full.",
    highlights: [
      'Migration `assets` + modèle `Asset` avec scopes',
      'Service `AssetImportService` idempotent',
      'Resource Filament `Médias` (browse + upload visuel)',
      'Procédure de déblocage quarantine documentée',
    ],
    Icon: ImageIcon,
  },
  {
    id: 'W',
    title: 'PR W — « À propos de nous » + correction PCP',
    description:
      "Renomme la rubrique « Le PSSFP » en « À propos de nous » avec dropdown sub-menu, corrige la terminologie (PCP au lieu de DG) et seede 9 pages avec le contenu réel des DOCX d'Anatole.",
    highlights: [
      'Mot du Président (texte officiel Pr. BASAHAG)',
      'Organigramme complet (~50 noms, 9 sections)',
      'Convention tripartite (MINFI · MINESUP · UY2)',
      'Histoire 2007-2026, Comité de Pilotage, Infrastructure',
      'Redirects 308 `/pssfp/*` → `/a-propos/*` (préservation SEO)',
    ],
    link: { href: '/a-propos', label: 'Visiter À propos' },
    Icon: Building2,
  },
  {
    id: 'X',
    title: 'PR X — Rubrique Formations refondue',
    description:
      "Bascule sur le contenu réel du catalogue officiel PSSFP (75 pages extraites) : Master à 1 185 000 FCFA/an, 5 spécialités catalogue (slugs `metiers-*`), 10 modules formation continue avec tarifs.",
    highlights: [
      '5 spécialités du Master Professionnel (catalogue)',
      '10 modules formation continue (3-5 jours, tarifs catalogue)',
      'Page Séminaires & voyages d\'étude (Maroc + France)',
      'Dropdown sub-menu Formations dans le header',
    ],
    link: { href: '/formations', label: 'Voir Formations' },
    Icon: GraduationCap,
  },
  {
    id: 'Y',
    title: 'PR Y — Hero showcase carrousel premium',
    description:
      "Remplace l'ancien hero par un carrousel Embla 5 slides avec photos institutionnelles, autoplay 6s, pause au hover, navigation clavier, prefers-reduced-motion respecté.",
    highlights: [
      '5 slides : Identité, Promotions, Formation continue, Convention, International',
      'Embla Carousel + autoplay (~7 KB gzip)',
      'A11y zéro violation critical (axe-core)',
      'Variant via env `NEXT_PUBLIC_HERO_VARIANT`',
    ],
    link: { href: '/', label: 'Voir le carrousel' },
    Icon: Sparkles,
  },
  {
    id: 'Z',
    title: 'PR Z — Wiring + 4 articles d\'accueil réels',
    description:
      "Supprime les MOCK_ARTICLES de la home et fetch les vrais articles publiés via `/v1/articles?featured=true`. 4 articles validés : formation continue, P14, Centre Pasteur (draft), Assemblée Nationale (draft).",
    highlights: [
      'Article phare : « Formation continue PSSFP — 10 modules »',
      'Article phare : « Lancement appel candidature P14 »',
      '2 articles draft (Centre Pasteur, Assemblée Nationale) — validation Anatole pending',
      'Audit `href="#"` : 0 occurrence résiduelle',
    ],
    link: { href: '/actualites', label: 'Voir Actualités' },
    Icon: Newspaper,
  },
  {
    id: 'AA',
    title: 'PR AA — Polish + démo COPIL prête',
    description:
      "Cette page de récapitulation, audit final i18n, ajout de `/demo-copil` exclue du sitemap et de robots.txt.",
    highlights: [
      'Page /demo-copil (vous y êtes)',
      'Robots.txt : Disallow /demo-copil',
      'Documentation Sprint S5 finalisée',
    ],
    Icon: CheckCircle2,
  },
];

const KPIS = [
  { label: 'Pages institutionnelles seedées', value: '9' },
  { label: 'Modules formation continue', value: '10' },
  { label: 'Spécialités Master catalogue', value: '5' },
  { label: 'Articles d\'accueil', value: '4' },
  { label: 'Slides carrousel hero', value: '5' },
  { label: 'Assets MinIO importés', value: '67' },
];

export default function DemoCopilPage(): JSX.Element {
  return (
    <article className="mx-auto max-w-5xl px-6 py-12 md:py-20">
      <header className="mb-12">
        <p className="pssfp-eyebrow text-[#C9A227]">Démonstration interne — Sprint S5</p>
        <h1 className="mt-3 font-heading text-4xl font-bold text-[#1A0A2E] md:text-5xl">
          Bouclage du site institutionnel
        </h1>
        <p className="mt-4 text-lg text-[#555]">
          Récapitulatif des 6 livrables du Sprint S5 pour la présentation au{' '}
          <strong>Comité de Pilotage</strong> présidé par le{' '}
          <strong>Pr. BASAHAG Achile Nestor</strong>.
        </p>
        <p className="mt-2 text-sm text-[#888]" data-testid="demo-copil-internal">
          Page interne — non indexée, non listée dans le sitemap.
        </p>
      </header>

      <section aria-labelledby="kpis-heading" className="mb-16">
        <h2 id="kpis-heading" className="font-heading text-2xl font-bold text-[#6B2FA0]">
          Sprint S5 en chiffres
        </h2>
        <ul className="mt-6 grid grid-cols-2 gap-4 md:grid-cols-3">
          {KPIS.map((kpi) => (
            <li
              key={kpi.label}
              className="rounded-pssfp-card border border-[#EDE7F6] bg-gradient-lavande-blanc p-6 text-center"
            >
              <p className="font-heading text-4xl font-bold text-[#6B2FA0]">{kpi.value}</p>
              <p className="mt-2 text-sm text-[#555]">{kpi.label}</p>
            </li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="prs-heading">
        <h2 id="prs-heading" className="font-heading text-2xl font-bold text-[#6B2FA0]">
          Les 6 PRs du Sprint
        </h2>
        <ol className="mt-6 space-y-6">
          {PRS.map((pr) => (
            <li
              key={pr.id}
              data-testid={`demo-copil-pr-${pr.id}`}
              className="rounded-pssfp-card border border-[#EDE7F6] bg-white p-6 shadow-pssfp-soft transition-all hover:shadow-pssfp-elevated md:p-8"
            >
              <div className="flex items-start gap-4">
                <span className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-violet-or text-white">
                  <pr.Icon size={22} aria-hidden="true" />
                </span>
                <div className="grow">
                  <h3 className="font-heading text-xl font-bold text-[#1A0A2E]">{pr.title}</h3>
                  <p className="mt-2 text-[#555]">{pr.description}</p>
                  <ul className="mt-4 space-y-1.5 text-sm text-[#444]">
                    {pr.highlights.map((h) => (
                      <li key={h} className="flex items-start gap-2">
                        <CheckCircle2
                          size={16}
                          aria-hidden="true"
                          className="mt-0.5 shrink-0 text-[#6B2FA0]"
                        />
                        <span>{h}</span>
                      </li>
                    ))}
                  </ul>
                  {pr.link && (
                    <Link
                      href={pr.link.href}
                      className="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-[#6B2FA0] transition-all hover:gap-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
                    >
                      {pr.link.label}
                      <ArrowRight size={14} aria-hidden="true" />
                    </Link>
                  )}
                </div>
              </div>
            </li>
          ))}
        </ol>
      </section>

      <section aria-labelledby="next-heading" className="mt-16">
        <h2 id="next-heading" className="font-heading text-2xl font-bold text-[#6B2FA0]">
          Prochaines étapes
        </h2>
        <div className="mt-6 rounded-pssfp-card border border-amber-300 bg-amber-50 p-6">
          <ul className="space-y-2 text-sm text-amber-900">
            <li className="flex items-start gap-2">
              <Users size={16} aria-hidden="true" className="mt-0.5 shrink-0" />
              <span>
                <strong>Validation rédactionnelle Anatole</strong> sur les 2 articles draft
                (Centre Pasteur, Assemblée Nationale) avant passage en publié.
              </span>
            </li>
            <li className="flex items-start gap-2">
              <ImageIcon size={16} aria-hidden="true" className="mt-0.5 shrink-0" />
              <span>
                <strong>Déblocage macOS quarantine</strong> sur 180 photos
                (<code className="rounded bg-amber-100 px-1">xattr -rd com.apple.quarantine assets-source/</code>)
                puis ré-import (<code className="rounded bg-amber-100 px-1">php artisan pssfp:import-assets</code>).
              </span>
            </li>
            <li className="flex items-start gap-2">
              <FileText size={16} aria-hidden="true" className="mt-0.5 shrink-0" />
              <span>
                <strong>Substitution photos Slide 1 & Slide 3</strong> du carrousel hero
                par les vraies photos campus / Centre Pasteur après déblocage.
              </span>
            </li>
            <li className="flex items-start gap-2">
              <Newspaper size={16} aria-hidden="true" className="mt-0.5 shrink-0" />
              <span>
                <strong>Audit Lighthouse production</strong> sur `/`,
                `/formations/specialites/metiers-fiscalite-comptabilite`,
                `/actualites`, `/contact` (cible ≥ 90 sur les 4 dimensions).
              </span>
            </li>
          </ul>
        </div>
      </section>
    </article>
  );
}
