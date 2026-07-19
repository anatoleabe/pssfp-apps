import Image from 'next/image';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface InternalPageHeroProps {
  eyebrow: string;
  title: string;
  excerpt?: string | null;
  imageSrc?: string | null;
  imageAlt?: string;
  imageMode?: 'cover' | 'panel';
  meta?: ReactNode;
}

export function InternalPageHero({
  eyebrow,
  title,
  excerpt,
  imageSrc,
  imageAlt = '',
  imageMode = 'cover',
  meta,
}: InternalPageHeroProps): JSX.Element {
  const panelImage = imageSrc && imageMode === 'panel';
  const coverImage = imageSrc && imageMode === 'cover';

  return (
    <header
      data-testid="internal-page-hero"
      className="relative isolate overflow-hidden border-b border-pssfp-or/35 bg-pssfp-prune-dark text-white"
    >
      {coverImage && (
        <>
          <Image
            src={imageSrc}
            alt={imageAlt}
            fill
            sizes="100vw"
            priority
            className="object-cover object-center"
          />
          <div
            aria-hidden="true"
            className="absolute inset-0 bg-[#201231]/80"
          />
        </>
      )}

      <div
        className={cn(
          'relative mx-auto grid max-w-7xl gap-8 px-6 py-12 md:py-16',
          panelImage && 'items-center lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.72fr)] lg:gap-12',
          coverImage && 'min-h-[340px] content-end md:min-h-[420px]',
        )}
      >
        <div className="max-w-4xl">
          <p className="pssfp-eyebrow text-pssfp-or-light">{eyebrow}</p>
          {meta && <div className="mt-4">{meta}</div>}
          <h1 className="mt-4 max-w-4xl font-heading text-4xl font-bold leading-[1.05] text-white md:text-5xl lg:text-6xl">
            {title}
          </h1>
          {excerpt && (
            <p
              className="mt-5 max-w-3xl text-lg leading-relaxed text-white/82 md:text-xl"
              data-testid="page-excerpt"
            >
              {excerpt}
            </p>
          )}
        </div>

        {panelImage && (
          <figure className="overflow-hidden rounded-pssfp-card border border-white/20 bg-white p-3 shadow-pssfp-floating">
            <div className="relative aspect-[3/1] w-full overflow-hidden bg-white">
              <Image
                src={imageSrc}
                alt={imageAlt}
                fill
                sizes="(max-width: 1024px) 100vw, 38vw"
                priority
                className="object-contain"
              />
            </div>
          </figure>
        )}
      </div>
    </header>
  );
}
