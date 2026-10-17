'use client';

import { useEffect } from 'react';
import { recordView } from '@/lib/api/library';

/** Compte une consultation depuis le navigateur ; ne rend rien. */
export function ViewBeacon({ uuid }: { uuid: string }): null {
  useEffect(() => {
    recordView(uuid);
  }, [uuid]);
  return null;
}
