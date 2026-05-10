import Image from 'next/image';
import { mediaUrl } from '@/lib/media';

/**
 * OrganigrammeChart — rend l'organigramme officiel du PSSFP en arbre
 * hiérarchique (cf. spec sprint S5 / pages institutionnelles).
 *
 * Hiérarchie (extraite de docs/sources/content/organigramme.md) :
 *
 *   Comité de Pilotage (Dr. BASAHAG)
 *      ├── Conseillers (juridique, technique)
 *      ├── Contrôleurs (gestion, interne)
 *      └── Comité Scientifique (Pr. AVOM)
 *          ├── Départements de Formation (5)
 *          └── Unités fonctionnelles (UPAAS, UAAF, USI, UDCFC, Centre Doc)
 *
 * Rendu : Server Component, lignes de connexion en CSS pur (pas de SVG runtime).
 * Photo optionnelle : si l'asset MinIO n'est pas trouvé, fallback initiales.
 */

interface OrgNode {
  id: string;
  name: string;
  role: string;
  /** Chemin photo dans bucket MinIO pssfp-media. Optionnel — fallback initiales. */
  photoPath?: string;
  /** Couleur d'accent : 'primary' (violet), 'or', 'lavande'. */
  accent?: 'primary' | 'or' | 'lavande';
  members?: string[];
}

const COMITE_PILOTAGE: OrgNode = {
  id: 'pcp',
  name: 'Dr. BASAHAG Achile Nestor',
  role: 'Président du Comité de Pilotage',
  photoPath: 'photos/direction/dr-basahag-achile.webp',
  accent: 'primary',
};

const COMITE_PILOTAGE_MEMBERS: OrgNode[] = [
  {
    id: 'conseiller-juridique',
    name: 'Pr. ABANE ENGOLO Patrick',
    role: 'Conseiller juridique',
    accent: 'or',
  },
  {
    id: 'conseiller-technique',
    name: 'Dr. BANOCK BAMBOCK Eric Vincent',
    role: 'Conseiller technique',
    accent: 'or',
  },
  {
    id: 'controle-gestion',
    name: 'M. MOLLO Davy Claude Aubin',
    role: 'Contrôleur de Gestion',
    accent: 'lavande',
  },
  {
    id: 'controle-interne',
    name: 'M. MELO LASSA FUESSOH',
    role: 'Contrôleur interne',
    accent: 'lavande',
  },
];

const COMITE_SCIENTIFIQUE: OrgNode = {
  id: 'cosci',
  name: 'Pr. AVOM Désiré',
  role: 'Président du Comité Scientifique',
  accent: 'primary',
};

const COMITE_SCIENTIFIQUE_MEMBERS: OrgNode[] = [
  {
    id: 'vp-cosci',
    name: 'Mme NDENDE Caroline',
    role: 'Vice-Présidente',
    accent: 'or',
  },
  {
    id: 'assistant-vp',
    name: 'M. FOUDA EKOUDI François',
    role: 'Assistant de la vice-Présidente',
    accent: 'or',
  },
];

const DEPARTEMENTS: OrgNode[] = [
  { id: 'dep-fiscalite', name: 'M. BILONG BI NGAWE Félix Ferry', role: 'Fiscalité, finance et comptabilité publique', accent: 'primary' },
  { id: 'dep-audit', name: 'M. NDJOM NACK Elie Désiré', role: 'Audit et contrôle', accent: 'primary' },
  { id: 'dep-economie', name: 'Pr. TAMBA Isaac', role: 'Économie publique et gestion publique', accent: 'primary' },
  { id: 'dep-gouvernance', name: 'Hon. MBARGA ASSEMBE Luc Roger', role: 'Gouvernance territoriale et FP locales', accent: 'primary' },
  { id: 'dep-marches', name: 'M. TCHOFFO Jean', role: 'Marchés publics et PPP', accent: 'primary' },
];

const UNITES: OrgNode[] = [
  {
    id: 'upaas',
    name: 'M. MBIANA Jean Paul',
    role: 'UPAAS — Programmation Activités Académiques et Scolarité',
    accent: 'or',
    members: ['M. NGUINI NYAMA Moïse Bertrand', 'M. ZOFOA KUMBI Solomon', 'M. BAVOUA Constant', 'M. ASSOA NGAH Evrard'],
  },
  {
    id: 'uaaf',
    name: 'M. MBA Pierre',
    role: 'UAAF — Affaires Administratives et Financières',
    accent: 'or',
    members: ['Mme BAHANAG Mathilde Joséphine', 'Mme ATSINA EBOGO Séraphine Madonne', 'M. AYISSI AKONO Joël Arnaud Malachie', 'Mme NGO KAMLA Marie Solange', 'Mme BOT Falone Nadia', 'M. MBARGA Joseph'],
  },
  {
    id: 'usi',
    name: 'M. ABE ETOUMOU Anatole',
    role: 'USI — Systèmes d\'Information',
    accent: 'or',
    members: ['M. BELINGA Joseph Cédric', 'M. MBIDA MBIDA Théodore Xavier', 'M. BELINGA Joseph Giresse', 'M. SIKE Manfred'],
  },
  {
    id: 'udcfc',
    name: 'Dr. MBALLA ZAMBO Edouard Georges',
    role: 'UDCFC — Développement, Coopération et Formation Continue',
    accent: 'or',
    members: ['Dr. MEMONO Jean Jacques Christian', 'Dr. LEVODO NGAH Gervais Yannick', 'Mme BONGO Daniela Anna'],
  },
  {
    id: 'centre-doc',
    name: 'M. BENOH BENOH Pierre Tanguy',
    role: 'Centre de Documentation',
    accent: 'lavande',
    members: ['Mme BEKONO MFOU\'OU Marlène'],
  },
];

const accentClasses = {
  primary: {
    border: 'border-[#6B2FA0]',
    bg: 'bg-gradient-to-br from-[#6B2FA0] to-[#9B59B6]',
    text: 'text-white',
    glow: 'shadow-[0_8px_32px_-8px_rgba(107,47,160,0.45)]',
  },
  or: {
    border: 'border-[#C9A227]',
    bg: 'bg-gradient-to-br from-[#FFFBEA] to-[#FFE9B0]',
    text: 'text-[#1A0A2E]',
    glow: 'shadow-[0_8px_32px_-8px_rgba(201,162,39,0.35)]',
  },
  lavande: {
    border: 'border-[#9B59B6]/40',
    bg: 'bg-gradient-to-br from-[#EDE7F6] to-[#FAF7FF]',
    text: 'text-[#1A0A2E]',
    glow: 'shadow-pssfp-soft',
  },
} as const;

function getInitials(name: string): string {
  const parts = name.replace(/^(Dr\.|Pr\.|M\.|Mme|Hon\.)\s+/i, '').split(/\s+/);
  return parts
    .slice(0, 2)
    .map((p) => p.charAt(0))
    .join('')
    .toUpperCase();
}

function PersonCard({ node, size = 'md' }: { node: OrgNode; size?: 'lg' | 'md' | 'sm' }): JSX.Element {
  const accent = accentClasses[node.accent ?? 'lavande'];
  const sizes = {
    lg: { card: 'min-w-[260px] p-6', avatar: 'h-24 w-24', name: 'text-lg', role: 'text-sm' },
    md: { card: 'min-w-[220px] p-5', avatar: 'h-16 w-16', name: 'text-base', role: 'text-xs' },
    sm: { card: 'min-w-[200px] p-4', avatar: 'h-12 w-12', name: 'text-sm', role: 'text-xs' },
  };
  const s = sizes[size];
  return (
    <div
      data-testid={`org-card-${node.id}`}
      className={`relative flex flex-col items-center gap-3 rounded-pssfp-card border-2 ${accent.border} ${size === 'lg' ? accent.bg : 'bg-white dark:bg-[#1F0E2E]'} ${s.card} ${accent.glow} transition-all duration-300 hover:-translate-y-1`}
    >
      <div className={`relative ${s.avatar} overflow-hidden rounded-full border-2 ${accent.border} bg-[#EDE7F6]`}>
        {node.photoPath ? (
          <Image
            src={mediaUrl(node.photoPath)}
            alt={`Portrait de ${node.name}`}
            fill
            sizes="120px"
            className="object-cover"
          />
        ) : (
          <span className={`flex h-full w-full items-center justify-center font-heading font-bold ${size === 'lg' ? 'text-3xl' : size === 'md' ? 'text-xl' : 'text-base'} text-[#6B2FA0]`}>
            {getInitials(node.name)}
          </span>
        )}
      </div>
      <div className="text-center">
        <p className={`font-heading font-bold leading-tight ${s.name} ${size === 'lg' ? accent.text : 'text-[#1A0A2E] dark:text-[#F5EFE3]'}`}>
          {node.name}
        </p>
        <p className={`mt-1 ${s.role} ${size === 'lg' ? 'text-white/85' : 'text-[#666] dark:text-[#B5A8C8]'}`}>
          {node.role}
        </p>
      </div>
      {node.members && node.members.length > 0 && (
        <details className="mt-2 w-full text-xs">
          <summary className={`cursor-pointer text-center font-medium ${size === 'lg' ? 'text-white/90' : 'text-[#6B2FA0] dark:text-[#B084E8]'} hover:underline`}>
            Voir l'équipe ({node.members.length})
          </summary>
          <ul className="mt-2 space-y-1 text-left text-[11px] text-[#555] dark:text-[#B5A8C8]">
            {node.members.map((m) => (
              <li key={m} className="border-l-2 border-[#C9A227]/40 pl-2">{m}</li>
            ))}
          </ul>
        </details>
      )}
    </div>
  );
}

function Connector(): JSX.Element {
  return (
    <div aria-hidden="true" className="flex justify-center">
      <div className="h-8 w-0.5 bg-gradient-to-b from-[#6B2FA0] to-[#C9A227]" />
    </div>
  );
}

export function OrganigrammeChart(): JSX.Element {
  return (
    <div data-testid="organigramme-chart" className="space-y-2 py-8">
      {/* Niveau 1 — Comité de Pilotage (PCP) */}
      <section aria-labelledby="org-copil" className="text-center">
        <h2 id="org-copil" className="pssfp-eyebrow text-[#C9A227] mb-4">
          Comité de Pilotage
        </h2>
        <div className="flex justify-center">
          <PersonCard node={COMITE_PILOTAGE} size="lg" />
        </div>
      </section>

      <Connector />

      {/* Niveau 1.5 — Conseillers et contrôleurs (autour du PCP) */}
      <section aria-labelledby="org-conseillers">
        <h3 id="org-conseillers" className="sr-only">Conseillers et contrôleurs</h3>
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
          {COMITE_PILOTAGE_MEMBERS.map((m) => (
            <PersonCard key={m.id} node={m} size="sm" />
          ))}
        </div>
      </section>

      <Connector />

      {/* Niveau 2 — Comité Scientifique */}
      <section aria-labelledby="org-cosci" className="text-center">
        <h2 id="org-cosci" className="pssfp-eyebrow text-[#C9A227] mb-4">
          Comité Scientifique
        </h2>
        <div className="flex justify-center">
          <PersonCard node={COMITE_SCIENTIFIQUE} size="lg" />
        </div>
        <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 mx-auto max-w-2xl">
          {COMITE_SCIENTIFIQUE_MEMBERS.map((m) => (
            <PersonCard key={m.id} node={m} size="sm" />
          ))}
        </div>
      </section>

      <Connector />

      {/* Niveau 3 — Départements de formation (5) */}
      <section aria-labelledby="org-departements">
        <h2 id="org-departements" className="pssfp-eyebrow text-[#C9A227] mb-4 text-center">
          5 Départements de Formation
        </h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {DEPARTEMENTS.map((d) => (
            <PersonCard key={d.id} node={d} size="md" />
          ))}
        </div>
      </section>

      <Connector />

      {/* Niveau 4 — Unités fonctionnelles */}
      <section aria-labelledby="org-unites">
        <h2 id="org-unites" className="pssfp-eyebrow text-[#C9A227] mb-4 text-center">
          Unités fonctionnelles & Centre de Documentation
        </h2>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {UNITES.map((u) => (
            <PersonCard key={u.id} node={u} size="md" />
          ))}
        </div>
      </section>
    </div>
  );
}
