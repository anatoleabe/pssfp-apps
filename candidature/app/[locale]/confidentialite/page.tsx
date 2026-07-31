import { getTranslations } from 'next-intl/server';

import { LegalDocument } from '@/components/LegalDocument';

type LegalSection = {
  heading: string;
  body?: string[];
  list?: string[];
};

export async function generateMetadata() {
  const t = await getTranslations('privacy');

  return { title: t('title'), description: t('metaDescription') };
}

/**
 * Politique de confidentialité.
 *
 * Le corps du document vit dans `messages/{locale}.json` : c'est un texte
 * opposable qui doit exister dans les deux langues et rester relisible par le
 * service juridique sans passer par le code.
 *
 * Points traités à la suite de l'audit du 30/07/2026 : droit applicable nommé
 * (A-25), durée de conservation des admis fixée (A-25), sous-traitants nommés
 * dont Cloudflare (A-25), caractère obligatoire de l'adresse électronique
 * rectifié (A-18), délai de réponse et procédure de violation ajoutés (A-25).
 */
export default async function ConfidentialitePage(): Promise<JSX.Element> {
  const t = await getTranslations('privacy');
  const sections = t.raw('sections') as LegalSection[];
  const contactEmail = t('contactEmail');

  return (
    <LegalDocument title={t('title')} updatedAt={t('updatedAt')}>
      <p>{t('intro')}</p>

      {sections.map((section) => (
        <section key={section.heading}>
          <h2>{section.heading}</h2>
          {section.body?.map((paragraph) => (
            <p key={paragraph}>{paragraph}</p>
          ))}
          {section.list && (
            <ul>
              {section.list.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          )}
        </section>
      ))}

      <p>
        {t('contactLabel')}{' '}
        <a href={`mailto:${contactEmail}`}>{contactEmail}</a>
      </p>
    </LegalDocument>
  );
}
