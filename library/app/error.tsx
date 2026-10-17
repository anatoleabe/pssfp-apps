'use client';

import { useRouter } from 'next/navigation';
import { useTransition } from 'react';
import { RotateCw } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { PRIMARY_ACTION, ServiceUnavailable } from '@/components/catalogue/ServiceUnavailable';

interface ErrorBoundaryProps {
  error: Error & { digest?: string };
  reset: () => void;
}

/**
 * Frontière d'erreur du segment racine (rendue dans app/layout.tsx : en-tête et pied de page restent).
 * Atteinte au premier rendu d'une page ISR dont l'API est indisponible (`unwrapOrThrow`).
 */
export default function ErrorBoundary({ reset }: ErrorBoundaryProps): JSX.Element {
  const t = useTranslations('errorPage');
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  // L'erreur vient d'un Server Component : il faut refaire la requête serveur avant `reset()`.
  const retry = (): void =>
    startTransition(() => {
      router.refresh();
      reset();
    });

  return (
    <ServiceUnavailable
      action={
        <button type="button" onClick={retry} aria-busy={pending} className={PRIMARY_ACTION}>
          <RotateCw size={16} aria-hidden="true" className={pending ? 'motion-safe:animate-spin' : undefined} />
          {t('retry')}
        </button>
      }
    />
  );
}
