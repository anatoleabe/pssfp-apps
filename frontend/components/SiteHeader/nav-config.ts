import {
  Award,
  BadgeCheck,
  Banknote,
  Briefcase,
  Building2,
  CalendarDays,
  ClipboardList,
  Compass,
  Globe2,
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
  groupKey: string;
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
        groupKey: 'menuGroupInstitution',
      },
      {
        href: '/a-propos/presentation',
        key: 'aproposPresentation',
        descriptionKey: 'aproposPresentationDesc',
        icon: Compass,
        groupKey: 'menuGroupInstitution',
      },
      {
        href: '/a-propos/comite-pilotage',
        key: 'aproposComitePilotage',
        descriptionKey: 'aproposComitePilotageDesc',
        icon: Users,
        groupKey: 'menuGroupGouvernance',
      },
      {
        href: '/a-propos/organigramme',
        key: 'aproposOrganigramme',
        descriptionKey: 'aproposOrganigrammeDesc',
        icon: Network,
        groupKey: 'menuGroupGouvernance',
      },
      {
        href: '/a-propos/convention-tripartite',
        key: 'aproposConvention',
        descriptionKey: 'aproposConventionDesc',
        icon: ScrollText,
        groupKey: 'menuGroupGouvernance',
      },
      {
        href: '/a-propos/histoire',
        key: 'aproposHistoire',
        descriptionKey: 'aproposHistoireDesc',
        icon: History,
        groupKey: 'menuGroupReperes',
      },
      {
        href: '/a-propos/infrastructure',
        key: 'aproposInfrastructure',
        descriptionKey: 'aproposInfrastructureDesc',
        icon: Building2,
        groupKey: 'menuGroupReperes',
      },
      {
        href: '/a-propos/partenaires',
        key: 'aproposPartenaires',
        descriptionKey: 'aproposPartenairesDesc',
        icon: Handshake,
        groupKey: 'menuGroupReperes',
      },
      {
        href: '/a-propos/conformite-cames',
        key: 'aproposCames',
        descriptionKey: 'aproposCamesDesc',
        icon: ShieldCheck,
        groupKey: 'menuGroupReperes',
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
        groupKey: 'menuGroupDiplomes',
      },
      {
        href: '/formations/formation-continue',
        key: 'formationsContinue',
        descriptionKey: 'formationsContinueDesc',
        icon: Layers,
        groupKey: 'menuGroupDeveloppement',
      },
      {
        href: '/formations/certifications',
        key: 'formationsCertifications',
        descriptionKey: 'formationsCertificationsDesc',
        icon: BadgeCheck,
        groupKey: 'menuGroupDeveloppement',
      },
      {
        href: '/formations/seminaires',
        key: 'formationsSeminaires',
        descriptionKey: 'formationsSeminairesDesc',
        icon: Award,
        groupKey: 'menuGroupDeveloppement',
      },
      {
        href: '/formations/admission',
        key: 'formationsAdmission',
        descriptionKey: 'formationsAdmissionDesc',
        icon: ClipboardList,
        groupKey: 'menuGroupPratique',
      },
      {
        href: '/formations/frais-de-scolarite',
        key: 'formationsFrais',
        descriptionKey: 'formationsFraisDesc',
        icon: Banknote,
        groupKey: 'menuGroupPratique',
      },
    ],
  },
  {
    href: '/vie-academique',
    key: 'vie',
    menuLabelKey: 'vieMenuLabel',
    children: [
      {
        href: '/vie-academique/promotions',
        key: 'viePromotions',
        descriptionKey: 'viePromotionsDesc',
        icon: GraduationCap,
        groupKey: 'menuGroupParcours',
      },
      {
        href: '/vie-academique/corps-enseignant',
        key: 'vieEnseignants',
        descriptionKey: 'vieEnseignantsDesc',
        icon: Users,
        groupKey: 'menuGroupParcours',
      },
      {
        href: '/vie-academique/calendrier-academique',
        key: 'vieCalendrier',
        descriptionKey: 'vieCalendrierDesc',
        icon: CalendarDays,
        groupKey: 'menuGroupCampus',
      },
      {
        href: '/vie-academique/stages-et-soutenances',
        key: 'vieStages',
        descriptionKey: 'vieStagesDesc',
        icon: Briefcase,
        groupKey: 'menuGroupCampus',
      },
      {
        href: '/vie-academique/programme-mediafip',
        key: 'vieMediafip',
        descriptionKey: 'vieMediafipDesc',
        icon: Award,
        groupKey: 'menuGroupOuverture',
      },
      {
        href: '/vie-academique/cooperation-internationale',
        key: 'vieCooperation',
        descriptionKey: 'vieCooperationDesc',
        icon: Globe2,
        groupKey: 'menuGroupOuverture',
      },
    ],
  },
  { href: '/actualites', key: 'actualites' },
  { href: '/contact', key: 'contact' },
] as const;
