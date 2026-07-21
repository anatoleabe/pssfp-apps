'use client';

export default function GlobalError({ reset }: { reset: () => void }): JSX.Element {
  return (
    <html lang="fr">
      <body style={{ margin: 0, fontFamily: 'system-ui, sans-serif', color: '#1A1A1A' }}>
        <main style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', padding: 24 }}>
          <div style={{ maxWidth: 560, textAlign: 'center' }}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/logos/pssfp.png" alt="PSSFP" width="88" height="88" />
            <h1 style={{ color: '#4A2E67', fontSize: 32 }}>Une erreur est survenue</h1>
            <p style={{ lineHeight: 1.6 }}>Une erreur est survenue de notre côté. Vos données sont en sécurité. Réessayez dans quelques instants ou contactez la scolarité.</p>
            <div style={{ display: 'flex', gap: 12, justifyContent: 'center', flexWrap: 'wrap', marginTop: 24 }}>
              <button type="button" onClick={reset} style={{ border: 0, borderRadius: 6, background: '#4A2E67', color: '#fff', padding: '12px 20px', cursor: 'pointer' }}>Réessayer</button>
              <a href="/" style={{ border: '1px solid #4A2E67', borderRadius: 6, color: '#4A2E67', padding: '12px 20px' }}>Retour à l&apos;accueil</a>
            </div>
            <p style={{ marginTop: 24, color: '#595959' }}>Scolarité : +237 222 234 567 · admissions@pssfp.org</p>
          </div>
        </main>
      </body>
    </html>
  );
}
