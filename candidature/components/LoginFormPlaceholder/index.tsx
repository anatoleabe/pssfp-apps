'use client';

import { useState, type FormEvent } from 'react';

export interface LoginFormPlaceholderLabels {
  phoneLabel: string;
  phonePlaceholder: string;
  phoneHelp: string;
  pinLabel: string;
  pinHelp: string;
  submit: string;
  forgotPin: string;
}

export interface LoginFormPlaceholderProps {
  labels: LoginFormPlaceholderLabels;
}

const PHONE_E164_REGEX = /^\+[1-9]\d{6,14}$/;
const PIN_REGEX = /^\d{6}$/;

export function LoginFormPlaceholder({ labels }: LoginFormPlaceholderProps) {
  const [phone, setPhone] = useState('');
  const [pin, setPin] = useState('');
  const [phoneTouched, setPhoneTouched] = useState(false);
  const [pinTouched, setPinTouched] = useState(false);

  const phoneValid = PHONE_E164_REGEX.test(phone);
  const pinValid = PIN_REGEX.test(pin);

  const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
    event.preventDefault();
    setPhoneTouched(true);
    setPinTouched(true);
  };

  return (
    <form className="mt-8 space-y-6" onSubmit={handleSubmit} noValidate>
      <div>
        <label htmlFor="phone" className="block text-sm font-medium text-[#333333]">
          {labels.phoneLabel}
        </label>
        <input
          id="phone"
          name="phone"
          type="tel"
          inputMode="tel"
          autoComplete="tel"
          required
          pattern="^\+[1-9]\d{6,14}$"
          placeholder={labels.phonePlaceholder}
          value={phone}
          onChange={(event) => setPhone(event.target.value.trim())}
          onBlur={() => setPhoneTouched(true)}
          aria-invalid={phoneTouched && !phoneValid}
          aria-describedby="phone-help"
          data-testid="login-phone-input"
          className="mt-1 block w-full rounded-md border border-gray-300 px-4 py-3 text-base focus:border-[#6B2FA0] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0]"
        />
        <p id="phone-help" className="mt-1 text-xs text-[#666666]">
          {labels.phoneHelp}
        </p>
      </div>

      <div>
        <label htmlFor="pin" className="block text-sm font-medium text-[#333333]">
          {labels.pinLabel}
        </label>
        <input
          id="pin"
          name="pin"
          type="password"
          inputMode="numeric"
          autoComplete="one-time-code"
          required
          pattern="^\d{6}$"
          value={pin}
          onChange={(event) => setPin(event.target.value.replace(/\D/g, '').slice(0, 6))}
          onBlur={() => setPinTouched(true)}
          aria-invalid={pinTouched && !pinValid}
          aria-describedby="pin-help"
          data-testid="login-pin-input"
          className="mt-1 block w-full rounded-md border border-gray-300 px-4 py-3 text-center text-2xl tracking-[0.5em] focus:border-[#6B2FA0] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0]"
        />
        <p id="pin-help" className="mt-1 text-xs text-[#666666]">
          {labels.pinHelp}
        </p>
      </div>

      <button
        type="submit"
        className="w-full rounded-md bg-[#6B2FA0] px-6 py-3 font-medium text-white hover:bg-[#9B59B6] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0] focus:ring-offset-2 disabled:opacity-50"
        disabled={!phoneValid || !pinValid}
      >
        {labels.submit}
      </button>

      <a href="#forgot-pin" className="block text-center text-sm text-[#6B2FA0] hover:underline">
        {labels.forgotPin}
      </a>
    </form>
  );
}
