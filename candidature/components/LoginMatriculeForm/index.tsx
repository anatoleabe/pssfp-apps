'use client';

import { useTranslations } from 'next-intl';
import { useState, useTransition, type FormEvent } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { loginMatriculeAction } from '@/app/[locale]/login/actions';

const MATRICULE_REGEX = /^\d{2}[A-Za-z]{2,6}\d{2,4}$/;

/**
 * Connexion d'un auditeur avec ses identifiants FOAD : matricule (identifiant
 * Moodle) et mot de passe, tels qu'indiqués sur sa lettre d'accès.
 */
export function LoginMatriculeForm(): JSX.Element {
  const t = useTranslations('login.matricule');
  const [identifiant, setIdentifiant] = useState('');
  const [motDePasse, setMotDePasse] = useState('');
  const [visible, setVisible] = useState(false);
  const [touched, setTouched] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const identifiantValide = MATRICULE_REGEX.test(identifiant.trim());
  const motDePasseValide = motDePasse.length > 0;

  const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
    event.preventDefault();
    setTouched(true);
    setErreur(null);
    if (!identifiantValide || !motDePasseValide) return;

    startTransition(async () => {
      const result = await loginMatriculeAction(identifiant.trim(), motDePasse);
      if (result.ok) {
        // Navigation complète : le header doit relire l'état de session (cf. LoginForm).
        window.location.assign(result.redirectTo);
        return;
      }
      setErreur(t(`errors.${result.errorKind}`));
    });
  };

  return (
    <form className="mt-8 space-y-6" onSubmit={handleSubmit} noValidate data-testid="login-matricule-form">
      <div>
        <label htmlFor="matricule" className="block text-sm font-medium text-[#333333]">
          {t('identifiantLabel')}
        </label>
        <input
          id="matricule"
          data-testid="login-matricule-input"
          type="text"
          autoComplete="username"
          // Ni majuscule ni correction automatique : le matricule se saisit
          // tel qu'écrit sur la lettre (l'API l'accepte dans les deux casses).
          autoCapitalize="none"
          autoCorrect="off"
          spellCheck={false}
          maxLength={20}
          value={identifiant}
          onChange={(e) => setIdentifiant(e.target.value.replace(/\s/g, ''))}
          aria-invalid={touched && !identifiantValide}
          aria-describedby={touched && !identifiantValide ? 'matricule-help matricule-error' : 'matricule-help'}
          placeholder="26efp001"
          className="mt-1 h-11 w-full rounded-md border border-gray-300 px-3 font-mono text-lg tracking-wide focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
        />
        <p id="matricule-help" className="mt-1 text-xs text-gray-500">{t('identifiantHelp')}</p>
        {touched && !identifiantValide && (
          <p id="matricule-error" role="alert" className="mt-1 text-sm text-red-700">{t('identifiantInvalid')}</p>
        )}
      </div>

      <div>
        <label htmlFor="mot-de-passe" className="block text-sm font-medium text-[#333333]">
          {t('motDePasseLabel')}
        </label>
        <div className="relative mt-1">
          <input
            id="mot-de-passe"
            data-testid="login-matricule-password"
            type={visible ? 'text' : 'password'}
            autoComplete="current-password"
            // Casse mixte : affiché en clair, le clavier du téléphone mettrait
            // une majuscule en tête ou corrigerait le mot de passe.
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck={false}
            maxLength={100}
            value={motDePasse}
            onChange={(e) => setMotDePasse(e.target.value)}
            aria-invalid={touched && !motDePasseValide}
            aria-describedby={touched && !motDePasseValide ? 'mot-de-passe-help mot-de-passe-error' : 'mot-de-passe-help'}
            className="h-11 w-full rounded-md border border-gray-300 px-3 pr-12 font-mono text-lg focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          />
          <button
            type="button"
            onClick={() => setVisible((v) => !v)}
            aria-label={visible ? t('masquer') : t('afficher')}
            aria-pressed={visible}
            className="absolute inset-y-0 right-0 inline-flex w-11 items-center justify-center rounded-r-md text-gray-500 hover:text-[#4A2E67] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67]"
          >
            {visible ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}
          </button>
        </div>
        <p id="mot-de-passe-help" className="mt-1 text-xs text-gray-500">{t('motDePasseHelp')}</p>
        {touched && !motDePasseValide && (
          <p id="mot-de-passe-error" role="alert" className="mt-1 text-sm text-red-700">{t('motDePasseRequired')}</p>
        )}
      </div>

      {erreur && (
        <div role="alert" data-testid="login-matricule-error" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
          {erreur}
        </div>
      )}

      <button
        type="submit"
        data-testid="login-matricule-submit"
        disabled={pending}
        className="flex h-11 w-full items-center justify-center rounded-md bg-[#4A2E67] text-sm font-medium text-white hover:bg-[#5C3A7E] disabled:cursor-not-allowed disabled:bg-gray-300"
      >
        {pending ? t('pending') : t('submit')}
      </button>

      <p className="text-center text-xs text-gray-500">{t('oubli')}</p>
    </form>
  );
}
