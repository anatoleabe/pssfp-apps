'use server';

import { verifierContenuDocument } from '@/lib/api/client';

export type ContenuVerifie = 'conforme' | 'different' | 'indisponible';

/**
 * Compare l'empreinte du PDF présenté à celle du document délivré. Seule l'empreinte
 * (64 caractères hexadécimaux) quitte le navigateur : le fichier n'est jamais envoyé.
 */
export async function verifierContenu(code: string, empreinte: string): Promise<ContenuVerifie> {
  if (!/^[A-Z0-9]{12}$/.test(code) || !/^[a-f0-9]{64}$/.test(empreinte)) {
    return 'indisponible';
  }
  const resultat = await verifierContenuDocument(code, empreinte);
  if (!resultat.ok || typeof resultat.data.conforme !== 'boolean') {
    return 'indisponible';
  }

  return resultat.data.conforme ? 'conforme' : 'different';
}
