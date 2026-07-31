import { getTranslations } from 'next-intl/server';

import { LegalDocument } from '@/components/LegalDocument';
import { Link } from '@/navigation';

type LegalSection = {
  heading: string;
  body?: string[];
  list?: string[];
};

export async function generateMetadata() {
  const t = await getTranslations('cgu');

  return { title: t('title'), description: t('metaDescription') };
}

/**
 * Conditions générales d'utilisation.
 *
 * Comme la politique de confidentialité, le corps vit dans
 * `messages/{locale}.json` : texte opposable, il doit exister dans les deux
 * langues et rester relisible sans passer par le code.
 */
export default async function CguPage(): Promise<JSX.Element> {
  const t = await getTranslations('cgu');
  const sections = t.raw('sections') as LegalSection[];

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
        {t('privacyRefBefore')}{' '}
        <Link href="/confidentialite">{t('privacyRefLink')}</Link>
        {t('privacyRefAfter')}
      </p>
    </LegalDocument>
  );
}
