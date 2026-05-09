'use client';

import { useRef, useState, useTransition } from 'react';
import { CheckCircle2, AlertCircle } from 'lucide-react';
import { submitContactAction } from '@/app/(public)/contact/actions';

type FormStatus =
  | { kind: 'idle' }
  | { kind: 'sending' }
  | { kind: 'success'; message: string }
  | { kind: 'error'; message: string; errors?: Record<string, string[]> };

const TURNSTILE_KEY = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY;

export function ContactForm(): JSX.Element {
  const formRef = useRef<HTMLFormElement>(null);
  const [status, setStatus] = useState<FormStatus>({ kind: 'idle' });
  const [pending, startTransition] = useTransition();

  const onSubmit = (event: React.FormEvent<HTMLFormElement>): void => {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    setStatus({ kind: 'sending' });
    startTransition(async () => {
      const result = await submitContactAction(formData);
      if (result.ok) {
        setStatus({ kind: 'success', message: result.message });
        formRef.current?.reset();
      } else {
        setStatus({
          kind: 'error',
          message: result.message,
          errors: result.errors,
        });
      }
    });
  };

  const fieldError = (key: string): string | undefined => {
    if (status.kind !== 'error' || !status.errors) return undefined;
    return status.errors[key]?.[0];
  };

  if (status.kind === 'success') {
    return (
      <div
        role="status"
        data-testid="contact-success"
        className="rounded-lg border border-emerald-300 bg-emerald-50 p-6"
      >
        <div className="flex items-start gap-3">
          <CheckCircle2 size={24} className="mt-0.5 shrink-0 text-emerald-700" aria-hidden="true" />
          <div>
            <p className="font-heading text-lg font-bold text-emerald-800">Message envoyé</p>
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
      <Field id="nom" label="Nom complet *" error={fieldError('nom')}>
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
        <Field id="email" label="Email *" error={fieldError('email')}>
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
        <Field id="phone" label="Téléphone (optionnel)" error={fieldError('phone')}>
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

      <Field id="organisation" label="Organisation (optionnel)" error={fieldError('organisation')}>
        <input
          id="organisation"
          name="organisation"
          type="text"
          autoComplete="organization"
          className={inputCls}
        />
      </Field>

      <Field id="subject" label="Sujet (optionnel)" error={fieldError('subject')}>
        <input
          id="subject"
          name="subject"
          type="text"
          className={inputCls}
        />
      </Field>

      <Field id="message" label="Message *" error={fieldError('message')}>
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

      <label className="flex items-start gap-3 text-sm text-[#333]">
        <input
          type="checkbox"
          name="cgu"
          required
          data-testid="contact-cgu"
          className="mt-1 h-4 w-4 rounded border-gray-300 text-[#6B2FA0] focus-visible:ring-[#6B2FA0]"
        />
        <span>
          J'ai pris connaissance de la <a href="/confidentialite" className="underline hover:text-[#6B2FA0]">politique de confidentialité</a> et j'accepte que mes données soient traitées pour répondre à ma demande.
        </span>
      </label>
      {fieldError('cgu') && (
        <p role="alert" className="text-xs text-red-700">{fieldError('cgu')}</p>
      )}

      {/* Cloudflare Turnstile — placeholder si pas de clé. Sinon, le widget Turnstile
          se chargera côté client via l'attribut data-sitekey (à brancher en PR de
          suivi quand la clé Turnstile sera créée par Anatole). */}
      {TURNSTILE_KEY ? (
        <div
          className="cf-turnstile"
          data-sitekey={TURNSTILE_KEY}
          data-testid="contact-turnstile"
        />
      ) : (
        <p
          role="note"
          data-testid="contact-turnstile-placeholder"
          className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900"
        >
          Captcha Cloudflare Turnstile désactivé en dev (clé non configurée).
        </p>
      )}

      {status.kind === 'error' && (
        <div
          role="alert"
          data-testid="contact-error"
          className="flex items-start gap-2 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700"
        >
          <AlertCircle size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
          <span>{status.message}</span>
        </div>
      )}

      <button
        type="submit"
        disabled={pending || status.kind === 'sending'}
        data-testid="contact-submit"
        className="inline-flex h-12 w-fit items-center justify-center rounded-md bg-[#6B2FA0] px-6 text-base font-medium text-white hover:bg-[#9B59B6] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 disabled:opacity-60"
      >
        {status.kind === 'sending' ? 'Envoi…' : 'Envoyer le message'}
      </button>
    </form>
  );
}

const inputCls =
  'h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#6B2FA0] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0]/30';

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
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-[#333]">
        {label}
      </label>
      {children}
      {error && (
        <p role="alert" className="mt-1 text-xs text-red-700">
          {error}
        </p>
      )}
    </div>
  );
}
