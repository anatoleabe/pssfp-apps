'use client';

import Image from 'next/image';
import Link from 'next/link';
import { useEffect } from 'react';

export default function ErrorPage({ error, reset }: { error: Error & { digest?: string }; reset: () => void }): JSX.Element {
  useEffect(() => {
    // Digest uniquement : aucune donnée candidat ni message d'exception.
    console.error('[candidature-ui-error]', { digest: error.digest ?? 'unknown' });
  }, [error]);

  return (
    <div className="mx-auto flex min-h-[65vh] max-w-xl flex-col items-center justify-center px-6 py-16 text-center">
      <Image src="/logos/pssfp.png" alt="PSSFP" width={88} height={88} priority />
      <p className="mt-6 font-ui text-xs font-semibold uppercase tracking-[0.18em] text-[#4A2E67]">Portail de candidature</p>
      <h1 className="mt-3 font-heading text-3xl font-bold text-[#1A1A1A]">Une erreur est survenue</h1>
      <p className="mt-4 text-base leading-relaxed text-[#4B4B4B]">
        Une erreur est survenue de notre côté. Vos données sont en sécurité. Réessayez dans quelques instants ou contactez la scolarité.
      </p>
      <div className="mt-7 flex flex-wrap justify-center gap-3">
        <button type="button" onClick={reset} className="rounded-md bg-[#4A2E67] px-5 py-3 text-sm font-semibold text-white hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2">Réessayer</button>
        <Link href="/" className="rounded-md border border-[#4A2E67] px-5 py-3 text-sm font-semibold text-[#4A2E67] hover:bg-[#F4EFFA] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2">Retour à l&apos;accueil</Link>
      </div>
      <p className="mt-7 text-sm text-[#595959]">
        Scolarité : <a className="underline" href="tel:+237222234567">+237 222 234 567</a>{' · '}<a className="underline" href="mailto:admissions@pssfp.org">admissions@pssfp.org</a>
      </p>
    </div>
  );
}
