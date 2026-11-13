import type { Metadata } from 'next';
import { getLocale, getTranslations } from 'next-intl/server';
import { FileDown, ShieldAlert } from 'lucide-react';
import { DEFAULT_LOCALE } from '@/i18n';
import { getLienLettreAcces } from '@/lib/api/client';
import { visiteurHeaders } from '@/lib/api/visiteur';
import { JETON_LIEN_LETTRE, motifErreurLienLettre } from '@/lib/lien-lettre';
import { Link } from '@/navigation';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('lienLettre');

  // Lien personnel reçu par SMS : jamais indexé.
  return { title: t('title'), robots: { index: false, follow: false } };
}

/**
 * Lien court de la lettre d'accès FOAD reçu par SMS. Rien n'est téléchargé à
 * l'ouverture : les messageries ouvrent les liens pour en faire un aperçu.
 * Le formulaire (sans JavaScript, pour tous les téléphones) poste la date de
 * naissance au relais, qui renvoie le PDF ou revient ici avec un motif.
 */
export default async function LienLettrePage({
  params,
  searchParams,
}: {
  params: { jeton: string };
  searchParams: { erreur?: string; restantes?: string };
}): Promise<JSX.Element> {
  const t = await getTranslations('lienLettre');
  const locale = await getLocale();
  const jetonValide = JETON_LIEN_LETTRE.test(params.jeton);
  const lien = jetonValide ? await getLienLettreAcces(params.jeton, await visiteurHeaders()) : null;
  // 410 : lien fermé. Une API momentanément injoignable laisse tenter le téléchargement.
  const ferme = !jetonValide || (lien !== null && !lien.ok && lien.status === 410);
  const erreur = motifErreurLienLettre(searchParams.erreur);
  const restantes = Number.parseInt(searchParams.restantes ?? '', 10);
  const expire = lien?.ok
    ? new Intl.DateTimeFormat(locale, { dateStyle: 'long', timeStyle: 'short', timeZone: 'Africa/Douala' }).format(new Date(lien.data.expire_le))
    : null;
  const action = `${locale === DEFAULT_LOCALE ? '' : `/${locale}`}/l/${params.jeton}/telecharger`;

  return (
    <main id="main" className="mx-auto max-w-xl px-6 py-12">
      <h1 className="font-heading text-3xl font-bold text-[#4A2E67]">{t('title')}</h1>

      {ferme ? (
        <section className="mt-8 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft" data-testid="lien-lettre-invalide">
          <p className="flex items-start gap-3 text-[#3C3C3C]">
            <ShieldAlert size={24} aria-hidden="true" className="shrink-0 text-[#8A1C1C]" />
            {t('invalide')}
          </p>
          <p className="mt-4 text-sm text-[#5C5C5C]">{t('invalideAide')}</p>
          <Link href="/login?mode=matricule" className="mt-6 inline-flex h-11 items-center rounded-md bg-[#4A2E67] px-5 text-sm font-semibold text-white hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2">
            {t('connexion')}
          </Link>
        </section>
      ) : (
        <section className="mt-8 rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft">
          <p className="text-[#3C3C3C]">{t('intro')}</p>
          <form method="post" action={action} className="mt-6 space-y-5" data-testid="lien-lettre-form">
            <div>
              <label htmlFor="date-naissance" className="block text-sm font-medium text-[#333333]">{t('dateLabel')}</label>
              <input
                id="date-naissance"
                name="date_naissance"
                type="date"
                required
                min="1930-01-01"
                aria-describedby="date-naissance-aide"
                className="mt-1 h-11 w-full rounded-md border border-gray-300 px-3 text-lg focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
              />
              <p id="date-naissance-aide" className="mt-1 text-xs text-gray-600">{t('dateHelp')}</p>
            </div>

            {erreur && (
              <p role="alert" data-testid="lien-lettre-erreur" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                {erreur === 'date' ? t('erreurs.date', { restantes: Number.isNaN(restantes) ? 0 : restantes }) : t(`erreurs.${erreur}`)}
              </p>
            )}

            <button type="submit" className="flex h-11 w-full items-center justify-center gap-2 rounded-md bg-[#4A2E67] text-sm font-semibold text-white hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2">
              <FileDown size={18} aria-hidden="true" />
              {t('submit')}
            </button>
          </form>
          {expire && <p className="mt-5 text-xs text-gray-600">{t('validite', { date: expire })}</p>}
        </section>
      )}
    </main>
  );
}
