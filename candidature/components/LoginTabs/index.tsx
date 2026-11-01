'use client';

import { useTranslations } from 'next-intl';
import { useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

type Mode = 'telephone' | 'matricule';

const MODES: Mode[] = ['telephone', 'matricule'];

interface LoginTabsProps {
  initialMode: Mode;
  telephone: ReactNode;
  matricule: ReactNode;
}

/**
 * Deux façons d'ouvrir son espace : téléphone + code à 6 chiffres (tous les
 * candidats), ou matricule + mot de passe FOAD (auditeurs). Onglets ARIA,
 * navigation au clavier par les flèches.
 */
export function LoginTabs({ initialMode, telephone, matricule }: LoginTabsProps): JSX.Element {
  const t = useTranslations('login.tabs');
  const [mode, setMode] = useState<Mode>(initialMode);
  const refs = useRef<Record<Mode, HTMLButtonElement | null>>({ telephone: null, matricule: null });

  const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>): void => {
    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
    event.preventDefault();
    const suivant: Mode = MODES[(MODES.indexOf(mode) + (event.key === 'ArrowRight' ? 1 : MODES.length - 1)) % MODES.length] ?? mode;
    setMode(suivant);
    refs.current[suivant]?.focus();
  };

  return (
    <div className="mt-8">
      <div role="tablist" aria-label={t('aria')} className="grid grid-cols-2 gap-1 rounded-pssfp-button bg-[#F3EFF7] p-1">
        {MODES.map((m) => (
          <button
            key={m}
            ref={(el) => {
              refs.current[m] = el;
            }}
            type="button"
            role="tab"
            id={`tab-${m}`}
            data-testid={`login-tab-${m}`}
            aria-selected={mode === m}
            aria-controls={`panel-${m}`}
            tabIndex={mode === m ? 0 : -1}
            onClick={() => setMode(m)}
            onKeyDown={onKeyDown}
            className={cn(
              'min-h-11 rounded-pssfp-button px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67]',
              mode === m ? 'bg-white text-[#4A2E67] shadow-pssfp-soft' : 'text-[#555555] hover:text-[#4A2E67]',
            )}
          >
            {t(m)}
          </button>
        ))}
      </div>
      <div role="tabpanel" id="panel-telephone" aria-labelledby="tab-telephone" hidden={mode !== 'telephone'}>
        {telephone}
      </div>
      <div role="tabpanel" id="panel-matricule" aria-labelledby="tab-matricule" hidden={mode !== 'matricule'}>
        {matricule}
        <p className="mt-4 rounded-md bg-[#FAF7F2] p-3 text-xs text-[#555555]">{t('matriculeNote')}</p>
      </div>
    </div>
  );
}
