import {
  Award,
  BadgeCheck,
  Banknote,
  Building2,
  ClipboardList,
  Compass,
  GraduationCap,
  Handshake,
  History,
  Layers,
  type LucideIcon,
  MessageSquareQuote,
  Network,
  ScrollText,
  ShieldCheck,
  Users,
} from 'lucide-react';

export interface NavLinkChild {
  href: string;
  key: string;
  icon: LucideIcon;
  descriptionKey: string;
}

export interface NavLink {
  href: string;
  key: string;
  menuLabelKey?: string;
  children?: NavLinkChild[];
}

export const NAV_LINKS: readonly NavLink[] = [
  { href: '/', key: 'home' },
  {
    href: '/a-propos',
    key: 'apropos',
    menuLabelKey: 'aproposMenuLabel',
    children: [
      {
        href: '/a-propos/mot-president',
        key: 'aproposMotPresident',
        descriptionKey: 'aproposMotPresidentDesc',
        icon: MessageSquareQuote,
      },
      {
        href: '/a-propos/presentation',
        key: 'aproposPresentation',
        descriptionKey: 'aproposPresentationDesc',
        icon: Compass,
      },
      {
        href: '/a-propos/comite-pilotage',
        key: 'aproposComitePilotage',
        descriptionKey: 'aproposComitePilotageDesc',
        icon: Users,
      },
      {
        href: '/a-propos/organigramme',
        key: 'aproposOrganigramme',
        descriptionKey: 'aproposOrganigrammeDesc',
        icon: Network,
      },
      {
        href: '/a-propos/convention-tripartite',
        key: 'aproposConvention',
        descriptionKey: 'aproposConventionDesc',
        icon: ScrollText,
      },
      {
        href: '/a-propos/histoire',
        key: 'aproposHistoire',
        descriptionKey: 'aproposHistoireDesc',
        icon: History,
      },
      {
        href: '/a-propos/infrastructure',
        key: 'aproposInfrastructure',
        descriptionKey: 'aproposInfrastructureDesc',
        icon: Building2,
      },
      {
        href: '/a-propos/partenaires',
        key: 'aproposPartenaires',
        descriptionKey: 'aproposPartenairesDesc',
        icon: Handshake,
      },
      {
        href: '/a-propos/conformite-cames',
        key: 'aproposCames',
        descriptionKey: 'aproposCamesDesc',
        icon: ShieldCheck,
      },
    ],
  },
  {
    href: '/formations',
    key: 'formations',
    menuLabelKey: 'formationsMenuLabel',
    children: [
      {
        href: '/formations/master',
        key: 'formationsMaster',
        descriptionKey: 'formationsMasterDesc',
        icon: GraduationCap,
      },
      {
        href: '/formations/formation-continue',
        key: 'formationsContinue',
        descriptionKey: 'formationsContinueDesc',
        icon: Layers,
      },
      {
        href: '/formations/certifications',
        key: 'formationsCertifications',
        descriptionKey: 'formationsCertificationsDesc',
        icon: BadgeCheck,
      },
      {
        href: '/formations/seminaires',
        key: 'formationsSeminaires',
        descriptionKey: 'formationsSeminairesDesc',
        icon: Award,
      },
      {
        href: '/formations/admission',
        key: 'formationsAdmission',
        descriptionKey: 'formationsAdmissionDesc',
        icon: ClipboardList,
      },
      {
        href: '/formations/frais-de-scolarite',
        key: 'formationsFrais',
        descriptionKey: 'formationsFraisDesc',
        icon: Banknote,
      },
    ],
  },
  { href: '/vie-academique', key: 'vie' },
  { href: '/actualites', key: 'actualites' },
  { href: '/contact', key: 'contact' },
] as const;
