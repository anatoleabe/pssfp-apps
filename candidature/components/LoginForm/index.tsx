'use client';

import { useState, useTransition, type FormEvent } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { loginAction } from '@/app/login/actions';
import { PhoneInput, type PhoneInputValue } from '@/components/PhoneInput';
import type { Pays } from '@/lib/api/types';

export interface LoginFormLabels {
  phoneLabel: string;
  phonePlaceholder: string;
  phoneHelp: string;
  pinLabel: string;
  pinHelp: string;
  submit: string;
  forgotPin: string;
}

export interface LoginFormProps {
  labels: LoginFormLabels;
  initialPhone?: string;
  reasonMessage?: string | null;
  pays: Pays[];
}

const PHONE_E164_REGEX = /^\+[1-9]\d{6,14}$/;
const PIN_REGEX = /^\d{6}$/;

export function LoginForm({ labels, initialPhone = '', reasonMessage, pays }: LoginFormProps): JSX.Element {
  const router = useRouter();
  const search = useSearchParams();
  const presetPhone = initialPhone || search.get('phone') || '';

  const initialCountry = pays.find((country) => presetPhone.startsWith(country.indicatif)) ?? pays.find((country) => country.code_iso === 'CM') ?? pays[0];
  const [phone, setPhone] = useState<PhoneInputValue>({
    countryCode: initialCountry?.code_iso ?? 'CM',
    indicatif: initialCountry?.indicatif ?? '+237',
    local: presetPhone.startsWith(initialCountry?.indicatif ?? '+237') ? presetPhone.slice((initialCountry?.indicatif ?? '+237').length) : presetPhone.replace(/\D/g, ''),
    e164: presetPhone,
  });
  const [pin, setPin] = useState('');
  const [phoneTouched, setPhoneTouched] = useState(false);
  const [pinTouched, setPinTouched] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const phoneValid = PHONE_E164_REGEX.test(phone.e164);
  const pinValid = PIN_REGEX.test(pin);

  const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
    event.preventDefault();
    setPhoneTouched(true);
    setPinTouched(true);
    setServerError(null);
    if (!phoneValid || !pinValid) {
      return;
    }

    startTransition(async () => {
      const result = await loginAction(phone.e164, pin);
      if (result.ok && result.redirectTo) {
        router.push(result.redirectTo);
        return;
      }
      setServerError(result.message ?? 'Erreur inconnue.');
    });
  };

  return (
    <form className="mt-8 space-y-6" onSubmit={handleSubmit} noValidate>
      {reasonMessage && (
        <div role="status" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          {reasonMessage}
        </div>
      )}

      <div>
        <label htmlFor="phone" className="block text-sm font-medium text-[#333333]">
          {labels.phoneLabel}
        </label>
        <div className="mt-1">
        <PhoneInput
          pays={pays}
          value={phone}
          onChange={setPhone}
          testIdPrefix="login-phone"
          numberTestId="login-phone-input"
          ariaInvalid={phoneTouched && !phoneValid}
          ariaDescribedBy={phoneTouched && !phoneValid ? 'phone-help phone-error' : 'phone-help'}
          onBlur={() => setPhoneTouched(true)}
        />
        </div>
        <p id="phone-help" className="mt-1 text-xs text-gray-500">
          {labels.phoneHelp}
        </p>
        {phoneTouched && !phoneValid && (
          <p id="phone-error" role="alert" className="mt-1 text-sm text-red-700">
            Saisissez un numéro valide, par exemple 6XXXXXXXX pour le Cameroun.
          </p>
        )}
      </div>

      <div>
        <label htmlFor="pin" className="block text-sm font-medium text-[#333333]">
          {labels.pinLabel}
        </label>
        <input
          id="pin"
          data-testid="login-pin-input"
          type="password"
          autoComplete="one-time-code"
          inputMode="numeric"
          pattern="^\d{6}$"
          required
          value={pin}
          onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 6))}
          onBlur={() => setPinTouched(true)}
          aria-invalid={pinTouched && !pinValid}
          aria-describedby={pinTouched && !pinValid ? 'pin-help pin-error' : 'pin-help'}
          className="mt-1 h-11 w-full rounded-md border border-gray-300 px-3 text-lg tracking-widest focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
        />
        <p id="pin-help" className="mt-1 text-xs text-gray-500">
          {labels.pinHelp}
        </p>
        {pinTouched && !pinValid && (
          <p id="pin-error" role="alert" className="mt-1 text-sm text-red-700">
            Saisissez les 6 chiffres de votre PIN.
          </p>
        )}
      </div>

      {serverError && (
        <div role="alert" data-testid="login-error" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
          {serverError}
        </div>
      )}

      <button
        type="submit"
        data-testid="login-submit"
        disabled={pending}
        className="flex h-11 w-full items-center justify-center rounded-md bg-[#4A2E67] text-sm font-medium text-white hover:bg-[#5C3A7E] disabled:cursor-not-allowed disabled:bg-gray-300"
      >
        {pending ? 'Connexion…' : labels.submit}
      </button>

      <Link
        href="/forgot-pin"
        className="block text-center text-sm text-[#4A2E67] underline hover:text-[#5C3A7E]"
      >
        {labels.forgotPin}
      </Link>
    </form>
  );
}
