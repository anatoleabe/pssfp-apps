interface JsonLdProps {
  data: Record<string, unknown>;
}

/**
 * Inline JSON-LD pour le SEO structuré (cf. spec module 1 PR P).
 *
 * À utiliser dans les Server Components (la balise <script type="application/ld+json">
 * est rendue côté serveur, pas d'exécution JS côté client).
 */
export function JsonLd({ data }: JsonLdProps): JSX.Element {
  return (
    <script
      type="application/ld+json"
      data-testid="json-ld"
      // JSON.stringify échappe correctement < et > pour empêcher l'injection
      // d'un </script> dans le payload.
      // eslint-disable-next-line react/no-danger
      dangerouslySetInnerHTML={{
        __html: JSON.stringify(data).replace(/</g, '\\u003c'),
      }}
    />
  );
}

const BASE_URL = process.env.NEXT_PUBLIC_SITE_URL ?? 'https://pssfp.org';

export function organizationJsonLd(): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'EducationalOrganization',
    name: 'PSSFP — Programme Supérieur de Spécialisation en Finances Publiques',
    alternateName: 'PSSFP',
    url: BASE_URL,
    logo: `${BASE_URL}/logos/pssfp.svg`,
    address: {
      '@type': 'PostalAddress',
      streetAddress: 'Campus de Messa',
      addressLocality: 'Yaoundé',
      addressCountry: 'CM',
    },
    contactPoint: {
      '@type': 'ContactPoint',
      contactType: 'customer service',
      email: 'contact@pssfp.org',
      telephone: '+237222234567',
      availableLanguage: ['fr'],
    },
    sameAs: ['https://www.facebook.com/pssfpcameroun'],
  };
}

export function programJsonLd(args: {
  name: string;
  description: string;
  slug: string;
}): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'EducationalOccupationalProgram',
    name: args.name,
    description: args.description,
    url: `${BASE_URL}/${args.slug}`,
    provider: {
      '@type': 'EducationalOrganization',
      name: 'PSSFP',
      url: BASE_URL,
    },
    educationalProgramMode: 'full-time',
    occupationalCategory: 'Public Finance',
  };
}

export function articleJsonLd(args: {
  title: string;
  description: string;
  slug: string;
  datePublished: string | null;
}): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'NewsArticle',
    headline: args.title,
    description: args.description,
    url: `${BASE_URL}/actualites/${args.slug}`,
    datePublished: args.datePublished,
    publisher: {
      '@type': 'EducationalOrganization',
      name: 'PSSFP',
      logo: {
        '@type': 'ImageObject',
        url: `${BASE_URL}/logos/pssfp.svg`,
      },
    },
  };
}
