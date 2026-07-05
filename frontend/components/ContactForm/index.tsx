'use client';

import { cloneElement, isValidElement, useEffect, useRef, useState, useTransition } from 'react';
import { CheckCircle2, AlertCircle } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { submitContactAction } from '@/app/(public)/contact/actions';
import { TurnstileWidget, isTurnstileEnabled } from '@/components/TurnstileWidget';

type FormStatus =
  | { kind: 'idle' }
  | { kind: 'sending' }
  | { kind: 'success'; message: string }
  | { kind: 'error'; message: string; errors?: Record<string, string[]> };

export function ContactForm(): JSX.Element {
  const t = useTranslations('contact.form');
  const tTurnstile = useTranslations('turnstile');
  const formRef = useRef<HTMLFormElement>(null);
  const successRef = useRef<HTMLDivElement>(null);
  const errorRef = useRef<HTMLDivElement>(null);
  const [status, setStatus] = useState<FormStatus>({ kind: 'idle' });
  const [turnstileToken, setTurnstileToken] = useState('');
  const [turnstileResetKey, setTurnstileResetKey] = useState(0);
  const [pending, startTransition] = useTransition();

  const turnstileMissing = isTurnstileEnabled() && turnstileToken.length === 0;

  // Focus programmé après soumission (WCAG 2.4.3) : le bloc succès remplace le
  // formulaire (focus perdu sur body), l'erreur peut désactiver le bouton focusé.
  useEffect(() => {
    if (status.kind === 'success') {
      successRef.current?.focus();
    }
    if (status.kind === 'error') {
      errorRef.current?.focus();
    }
  }, [status.kind]);

  const onSubmit = (event: React.FormEvent<HTMLFormElement>): void => {
    event.preventDefault();
    // Bouton aria-disabled (focusable, perceptible) : la garde se fait ici.
    if (pending || status.kind === 'sending' || turnstileMissing) {
      return;
    }
    const formData = new FormData(event.currentTarget);
    formData.set('cf_turnstile_response', turnstileToken);
    setStatus({ kind: 'sending' });
    startTransition(async () => {
      const result = await submitContactAction(formData);
      if (result.ok) {
        setStatus({ kind: 'success', message: result.message ?? t('fallbackSuccess') });
        formRef.current?.reset();
        return;
      }
      setStatus({
        kind: 'error',
        message: result.message ?? t('networkError'),
        errors: result.errors,
      });
      // Token single-use : consommé côté siteverify même sur échec — régénérer.
      setTurnstileToken('');
      setTurnstileResetKey((k) => k + 1);
    });
  };

  const fieldError = (key: string): string | undefined => {
    if (status.kind !== 'error' || !status.errors) return undefined;
    return status.errors[key]?.[0];
  };

  if (status.kind === 'success') {
    return (
      <div
        ref={successRef}
        tabIndex={-1}
        role="status"
        data-testid="contact-success"
        className="rounded-lg border border-emerald-300 bg-emerald-50 p-6 focus:outline-none"
      >
        <div className="flex items-start gap-3">
          <CheckCircle2 size={24} className="mt-0.5 shrink-0 text-emerald-700" aria-hidden="true" />
          <div>
            <p className="font-heading text-lg font-bold text-emerald-800">{t('successTitle')}</p>
            <p className="mt-2 text-sm text-emerald-900">{status.message}</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <form
      ref={formRef}
      onSubmit={onSubmit}
      data-testid="contact-form"
      className="grid gap-4"
      noValidate
    >
      <Field id="nom" label={t('nom')} error={fieldError('nom')}>
        <input
          id="nom"
          name="nom"
          type="text"
          required
          autoComplete="name"
          className={inputCls}
          data-testid="contact-nom"
        />
      </Field>

      <div className="grid gap-4 md:grid-cols-2">
        <Field id="email" label={t('email')} error={fieldError('email')}>
          <input
            id="email"
            name="email"
            type="email"
            required
            autoComplete="email"
            className={inputCls}
            data-testid="contact-email"
          />
        </Field>
        <Field id="phone" label={t('phone')} error={fieldError('phone')}>
          <input
            id="phone"
            name="phone"
            type="tel"
            autoComplete="tel"
            className={inputCls}
            data-testid="contact-phone"
          />
        </Field>
      </div>

      <Field id="organisation" label={t('organisation')} error={fieldError('organisation')}>
        <input
          id="organisation"
          name="organisation"
          type="text"
          autoComplete="organization"
          className={inputCls}
        />
      </Field>

      <Field id="subject" label={t('subject')} error={fieldError('subject')}>
        <input
          id="subject"
          name="subject"
          type="text"
          className={inputCls}
        />
      </Field>

      <Field id="message" label={t('message')} error={fieldError('message')}>
        <textarea
          id="message"
          name="message"
          required
          rows={6}
          minLength={10}
          maxLength={5000}
          data-testid="contact-message"
          className={`${inputCls} h-auto resize-y py-2`}
        />
      </Field>

      <label className="flex items-start gap-3 text-sm text-[#333] dark:text-[#C9C2D8]">
        <input
          type="checkbox"
          name="cgu"
          required
          data-testid="contact-cgu"
          aria-invalid={Boolean(fieldError('cgu'))}
          aria-describedby={fieldError('cgu') ? 'cgu-error' : undefined}
          className="mt-1 h-4 w-4 rounded border-gray-300 text-[#4A2E67] focus-visible:ring-[#4A2E67]"
        />
        <span>
          {t.rich('consent', {
            a: (chunks) => (
              <a href="/confidentialite" className="underline hover:text-[#4A2E67]">
                {chunks}
              </a>
            ),
          })}
        </span>
      </label>
      {fieldError('cgu') && (
        <p id="cgu-error" role="alert" className="text-xs text-red-700 dark:text-red-300">
          {fieldError('cgu')}
        </p>
      )}

      {isTurnstileEnabled() ? (
        <div className="space-y-2">
          <span id="contact-turnstile-heading" className="block text-sm font-medium text-[#333] dark:text-[#C9C2D8]">
            {tTurnstile('heading')}
          </span>
          <TurnstileWidget
            action="contact-form"
            resetKey={turnstileResetKey}
            onVerify={setTurnstileToken}
            onExpire={() => setTurnstileToken('')}
          />
          {fieldError('cf_turnstile_response') && (
            <p role="alert" className="text-xs text-red-700 dark:text-red-300">
              {fieldError('cf_turnstile_response')}
            </p>
          )}
        </div>
      ) : (
        <p
          role="note"
          data-testid="contact-turnstile-placeholder"
          className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900"
        >
          {tTurnstile('devPlaceholder')}
        </p>
      )}

      {status.kind === 'error' && (
        <div
          ref={errorRef}
          tabIndex={-1}
          role="alert"
          data-testid="contact-error"
          className="flex items-start gap-2 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700 focus:outline-none"
        >
          <AlertCircle size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
          <span>{status.message}</span>
        </div>
      )}

      {/* aria-disabled plutôt que disabled quand seul le token manque : le
          bouton reste focusable/perceptible, la garde est dans onSubmit. */}
      <button
        type="submit"
        disabled={pending || status.kind === 'sending'}
        aria-disabled={pending || status.kind === 'sending' || turnstileMissing}
        aria-describedby={turnstileMissing ? 'contact-turnstile-heading' : undefined}
        data-testid="contact-submit"
        className="inline-flex h-12 w-fit items-center justify-center rounded-md bg-[#4A2E67] px-6 text-base font-medium text-white hover:bg-[#5C3A7E] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 disabled:opacity-60 aria-disabled:opacity-60 aria-disabled:hover:bg-[#4A2E67]"
      >
        {status.kind === 'sending' ? t('sending') : t('submit')}
      </button>
    </form>
  );
}

const inputCls =
  'h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30 dark:focus:border-[#B084E8] dark:focus:ring-[#B084E8]/40';

function Field({
  id,
  label,
  error,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  children: React.ReactNode;
}): JSX.Element {
  const errorId = `${id}-error`;
  // Erreur reliée programmatiquement au champ (WCAG 1.3.1/4.1.2).
  const child =
    isValidElement(children) && error
      ? cloneElement(children as React.ReactElement, {
          'aria-invalid': true,
          'aria-describedby': errorId,
        })
      : children;

  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-[#333] dark:text-[#C9C2D8]">
        {label}
      </label>
      {child}
      {error && (
        <p id={errorId} role="alert" className="mt-1 text-xs text-red-700 dark:text-red-300">
          {error}
        </p>
      )}
    </div>
  );
}
