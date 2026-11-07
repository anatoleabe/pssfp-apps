'use client';

import { useTranslations } from 'next-intl';
import { FileText, GraduationCap, House, KeyRound, MessageSquare, UserRound, Wallet } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Link, usePathname } from '@/navigation';

export type Rubrique = 'accueil' | 'parcours' | 'scolarite' | 'acces' | 'documents' | 'messages' | 'profil';

const RUBRIQUES: { cle: Rubrique; href: string; icone: LucideIcon }[] = [
  { cle: 'accueil', href: '/dossier', icone: House },
  { cle: 'parcours', href: '/dossier/parcours', icone: GraduationCap },
  { cle: 'scolarite', href: '/dossier/scolarite', icone: Wallet },
  { cle: 'acces', href: '/dossier/acces', icone: KeyRound },
  { cle: 'documents', href: '/dossier/documents', icone: FileText },
  { cle: 'messages', href: '/dossier/messages', icone: MessageSquare },
  { cle: 'profil', href: '/dossier/profil', icone: UserRound },
];

/** Barre des rubriques : défile horizontalement sur mobile, rubrique active signalée. */
export function EspaceNav(): JSX.Element {
  const t = useTranslations('espace');
  const pathname = usePathname();

  return (
    <nav aria-label={t('navLabel')} data-testid="espace-nav" className="-mx-6 mb-8 overflow-x-auto px-6 py-1">
      <ul className="flex min-w-max gap-2 border-b border-[#F4EFFA] pb-px">
        {RUBRIQUES.map(({ cle, href, icone: Icone }) => {
          const actif = pathname === href;
          return (
            <li key={cle}>
              <Link
                href={href}
                aria-current={actif ? 'page' : undefined}
                className={`inline-flex min-h-11 items-center gap-2 rounded-t-pssfp-button border-b-2 px-4 text-sm font-semibold transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#4A2E67] ${
                  actif ? 'border-[#4A2E67] text-[#4A2E67]' : 'border-transparent text-[#3C3C3C] hover:border-[#A592BD] hover:text-[#4A2E67]'
                }`}
              >
                <Icone size={16} aria-hidden />
                {t(`nav.${cle}`)}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
