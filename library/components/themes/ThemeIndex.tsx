import Link from 'next/link';
import { ArrowUpRight } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { themeHref } from '@/lib/routes';
import type { ThemeNode } from '@/lib/themes';

const CARD =
  'group flex h-full min-h-14 items-center justify-between gap-3 rounded-xl border border-pssfp-lavande/45 bg-white px-4 py-3 transition duration-300 ease-pssfp-out-expo hover:border-pssfp-prune/50 hover:bg-pssfp-mist focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 motion-reduce:transition-none';

/** Lien vers un thème : libellé, nombre de documents s'il est connu. */
function ThemeLink({ node }: { node: ThemeNode }): JSX.Element {
  const t = useTranslations('themes');
  return (
    <Link href={themeHref(node.slug)} data-testid={`theme-link-${node.slug}`} className={CARD}>
      <span className="flex min-w-0 flex-col">
        <span className="font-ui text-[0.9375rem] font-semibold text-pssfp-prune-dark [overflow-wrap:anywhere]">{node.label}</span>
        {node.count !== null && <span className="font-ui text-xs text-pssfp-graphite-light">{t('count', { count: node.count })}</span>}
      </span>
      <ArrowUpRight size={16} aria-hidden="true" className="shrink-0 text-pssfp-prune-light transition-transform duration-300 motion-safe:group-hover:-translate-y-0.5 motion-safe:group-hover:translate-x-0.5" />
    </Link>
  );
}

function ThemeGrid({ nodes }: { nodes: ThemeNode[] }): JSX.Element {
  return (
    <ul className="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      {nodes.map((node) => (
        <li key={node.slug}>
          <ThemeLink node={node} />
        </li>
      ))}
    </ul>
  );
}

/**
 * Index du thésaurus : un groupe par racine (Thèmes, Institutions, Types de textes), sommaire d'ancres,
 * niveau intermédiaire conservé (sous-titre lié à sa propre page, puis ses enfants).
 */
interface ThemeIndexProps {
  tree: ThemeNode[];
  /** Préfixe des ancres et identifiants (plusieurs index sur une même page de démonstration). */
  idPrefix?: string;
}

export function ThemeIndex({ tree, idPrefix = '' }: ThemeIndexProps): JSX.Element {
  const t = useTranslations('themes');
  return (
    <div data-testid="theme-index" className="flex flex-col gap-14 lg:gap-16">
      <nav aria-label={t('sectionsLabel')}>
        <ul className="flex flex-wrap gap-2">
          {tree.map((root) => (
            <li key={root.slug}>
              <a
                href={`#${idPrefix}groupe-${root.slug}`}
                className="inline-flex min-h-9 items-center gap-2 rounded-full border border-pssfp-prune/40 bg-white px-4 font-ui text-sm font-medium text-pssfp-prune-dark transition-colors hover:border-pssfp-prune hover:bg-pssfp-mist focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
              >
                {root.label}
                {root.count !== null && <span className="text-xs font-normal text-pssfp-graphite-light">{t('count', { count: root.count })}</span>}
              </a>
            </li>
          ))}
        </ul>
      </nav>

      {tree.map((root) => {
        const leaves = root.children.filter((n) => n.children.length === 0);
        const groups = root.children.filter((n) => n.children.length > 0);
        return (
          <section key={root.slug} id={`${idPrefix}groupe-${root.slug}`} data-testid={`theme-group-${root.slug}`} aria-labelledby={`${idPrefix}titre-${root.slug}`} className="scroll-mt-28">
            <div className="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2 border-b border-pssfp-lavande/40 pb-4">
              <h2 id={`${idPrefix}titre-${root.slug}`} className="font-heading text-pssfp-h2 font-semibold text-pssfp-prune-dark">
                {root.label}
              </h2>
              {/* Pages de regroupement : seulement si l'API compte (et donc agrège) les sous-thèmes. */}
              {root.count !== null && (
                <Link href={themeHref(root.slug)} className="rounded-sm font-ui text-sm font-semibold text-pssfp-prune underline decoration-pssfp-lavande underline-offset-4 transition-colors hover:decoration-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2">
                  {t('allOf', { label: root.label })}
                </Link>
              )}
            </div>
            {leaves.length > 0 && (
              <div className="mt-6">
                <ThemeGrid nodes={leaves} />
              </div>
            )}
            {groups.map((group) => (
              <div key={group.slug} className="mt-8">
                <h3 className="flex flex-wrap items-baseline gap-x-3 font-heading text-2xl font-semibold text-pssfp-prune-dark">
                  {group.count !== null ? (
                    <>
                      <Link href={themeHref(group.slug)} className="rounded-sm underline decoration-pssfp-lavande decoration-1 underline-offset-[6px] transition-colors hover:text-pssfp-prune hover:decoration-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2">
                        {group.label}
                      </Link>
                      <span className="font-ui text-sm font-normal text-pssfp-graphite-light">{t('count', { count: group.count })}</span>
                    </>
                  ) : (
                    group.label
                  )}
                </h3>
                <div className="mt-4">
                  <ThemeGrid nodes={group.children} />
                </div>
              </div>
            ))}
          </section>
        );
      })}
    </div>
  );
}
