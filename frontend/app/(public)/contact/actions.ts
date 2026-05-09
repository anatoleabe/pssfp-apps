'use server';

import { sendContactMessage, type ContactPayload, type ContactResult } from '@/lib/api/contact';

export async function submitContactAction(formData: FormData): Promise<ContactResult> {
  const payload: ContactPayload = {
    nom: String(formData.get('nom') ?? ''),
    email: String(formData.get('email') ?? ''),
    phone: String(formData.get('phone') ?? '') || undefined,
    organisation: String(formData.get('organisation') ?? '') || undefined,
    subject: String(formData.get('subject') ?? '') || undefined,
    message: String(formData.get('message') ?? ''),
    cgu: formData.get('cgu') === 'on' || formData.get('cgu') === 'true',
    cf_turnstile_response: String(formData.get('cf_turnstile_response') ?? '') || undefined,
  };
  return sendContactMessage(payload);
}
