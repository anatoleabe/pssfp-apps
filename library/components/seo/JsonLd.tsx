interface JsonLdProps {
  data: Record<string, unknown>;
}

/**
 * JSON-LD inline pour le SEO structuré (copié de `frontend/components/JsonLd`).
 * Server Component : la balise est rendue côté serveur, sans JS client.
 */
export function JsonLd({ data }: JsonLdProps): JSX.Element {
  return (
    <script
      type="application/ld+json"
      data-testid="json-ld"
      // JSON.stringify + échappement de « < » : impossible d'injecter un </script>.
      // eslint-disable-next-line react/no-danger
      dangerouslySetInnerHTML={{ __html: JSON.stringify(data).replace(/</g, '\\u003c') }}
    />
  );
}

/** `WebSite` + `SearchAction` : champ de recherche du site dans les résultats des moteurs. */
export function websiteJsonLd(args: { name: string; description: string; origin: string }): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    name: args.name,
    description: args.description,
    url: `${args.origin}/`,
    inLanguage: 'fr',
    potentialAction: {
      '@type': 'SearchAction',
      target: { '@type': 'EntryPoint', urlTemplate: `${args.origin}/recherche?q={search_term_string}` },
      'query-input': 'required name=search_term_string',
    },
  };
}
