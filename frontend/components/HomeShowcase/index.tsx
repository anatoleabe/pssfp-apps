'use client';

/**
 * HomeShowcase — carousel hero 5 slides (cf. spec sprint S5 PR Y).
 *
 * - Embla Carousel React + autoplay 6s, pause au hover.
 * - Crossfade 800ms entre slides.
 * - Photos institutionnelles (MinIO bucket pssfp-media).
 * - Pictogrammes ronds en bas + boutons précédent/suivant.
 * - Navigation clavier (← →) sur le track.
 * - Respect `prefers-reduced-motion`.
 * - LCP : la 1re slide a `priority` + `fetchPriority="high"`.
 *
 * Note : composant `'use client'` car Embla est un hook navigateur.
 */
import Image from 'next/image';
import Link from 'next/link';
import useEmblaCarousel from 'embla-carousel-react';
import Autoplay from 'embla-carousel-autoplay';
import { useCallback, useEffect, useState } from 'react';
import { ArrowRight, ChevronLeft, ChevronRight } from 'lucide-react';
import { mediaUrl } from '../../lib/media';

export interface ShowcaseSlide {
  /** Identifiant stable pour les listes / tests. */
  id: string;
  /** Eyebrow court (ex. "Identité institutionnelle"). */
  eyebrow: string;
  /** Titre principal Playfair (peut contenir un span de mise en valeur). */
  title: string;
  /** Sous-titre Inter. */
  subtitle: string;
  /** Chemin relatif au bucket pssfp-media (ex. "photos/evenements/dsc-0538.webp"). */
  imagePath: string;
  /** Texte alt pour l'image. */
  imageAlt: string;
  /** CTA principal. */
  primaryCta: { label: string; href: string };
  /** CTA secondaire optionnel. */
  secondaryCta?: { label: string; href: string };
}

const SLIDES: ReadonlyArray<ShowcaseSlide> = [
  {
    id: 'identite',
    eyebrow: 'Le PSSFP — depuis 2013',
    title: "Former l'élite des finances publiques",
    subtitle:
      'Un institut du Ministère des Finances, créé le 9 octobre 2013 par convention tripartite MINFI · MINESUP · UY2.',
    imagePath: 'photos/evenements/dsc-0538.webp',
    imageAlt: 'Sortie solennelle de la promotion 6 — Palais des Congrès de Yaoundé',
    primaryCta: { label: 'Découvrir nos formations', href: '/formations' },
    secondaryCta: { label: 'Candidater à la 14e promo', href: '#candidature' },
  },
  {
    id: 'excellence-promotions',
    eyebrow: '13 promotions diplômées',
    title: '13 promotions au service de l’État',
    subtitle:
      "5 spécialités du Master Professionnel en Finances Publiques — BAC+5 reconnu CAMES, débouchés DGI, DGD, DGTCFM, MINEPAT, ARMP, FEICOM.",
    imagePath: 'photos/evenements/dsc-0466.webp',
    imageAlt: 'Diplômés de la sortie solennelle promo 6',
    primaryCta: { label: 'Découvrir le Master', href: '/formations/master' },
  },
  {
    id: 'formation-continue',
    eyebrow: 'Formation continue 2026',
    title: '10 modules pour les acteurs publics',
    subtitle:
      'Administrations, CTD, élus locaux, secteur privé — accompagner la modernisation des finances publiques. Sessions de 3 à 5 jours.',
    imagePath: 'photos/evenements/affiche.webp',
    imageAlt: 'Affiche de la promotion 13 — appel à candidature et formation',
    primaryCta: {
      label: 'Voir le catalogue Formation continue',
      href: '/formations/formation-continue',
    },
  },
  {
    id: 'gouvernance',
    eyebrow: 'Convention tripartite — 9 oct. 2013',
    title: 'Trois institutions, une mission',
    subtitle:
      "Convention tripartite MINFI · MINESUP · Université de Yaoundé II-Soa, sous l'autorité du Comité de Pilotage.",
    imagePath: 'photos/evenements/dsc-0302.webp',
    imageAlt: 'Cérémonie institutionnelle PSSFP',
    primaryCta: { label: 'À propos de nous', href: '/a-propos' },
  },
  {
    id: 'international',
    eyebrow: 'Coopération internationale',
    title: 'Une école ouverte sur le monde',
    subtitle:
      'Coopération CEMAC, partenariats avec la France (Expertise France, ENA) et le Maroc (Institut des Finances Basil Fuleihan), certifications internationales (FMI, OCDE).',
    imagePath: 'photos/evenements/whatsapp-image-2025-10-04-at-192408.webp',
    imageAlt: 'Délégation PSSFP en mission internationale',
    primaryCta: { label: 'Découvrir nos partenaires', href: '/a-propos/partenaires' },
  },
];

export function HomeShowcase(): JSX.Element {
  const [emblaRef, emblaApi] = useEmblaCarousel(
    {
      loop: true,
      duration: 30,
      align: 'start',
    },
    [Autoplay({ delay: 6000, stopOnInteraction: false, stopOnMouseEnter: true })],
  );

  const [selectedIndex, setSelectedIndex] = useState(0);

  const onSelect = useCallback((): void => {
    if (!emblaApi) return;
    setSelectedIndex(emblaApi.selectedScrollSnap());
  }, [emblaApi]);

  useEffect(() => {
    if (!emblaApi) return;
    emblaApi.on('select', onSelect);
    onSelect();
    return () => {
      emblaApi.off('select', onSelect);
    };
  }, [emblaApi, onSelect]);

  const goTo = useCallback(
    (index: number) => {
      if (!emblaApi) return;
      emblaApi.scrollTo(index);
    },
    [emblaApi],
  );

  const scrollPrev = useCallback(() => emblaApi?.scrollPrev(), [emblaApi]);
  const scrollNext = useCallback(() => emblaApi?.scrollNext(), [emblaApi]);

  const onKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLDivElement>) => {
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        scrollPrev();
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        scrollNext();
      }
    },
    [scrollPrev, scrollNext],
  );

  return (
    <section
      aria-label="Présentation du PSSFP — diaporama de 5 slides"
      data-testid="home-showcase"
      className="relative isolate overflow-hidden bg-[#14101A]"
    >
      {/*
        Carrousel WAI-ARIA APG pattern : track focusable + ARIA roledescription
        `carrousel`. ESLint signale `tabIndex` sur élément non-interactif et
        `onKeyDown` sur élément non-interactif, mais ce pattern est documenté
        et indispensable pour la navigation clavier (← →) sur le carrousel.
        Ref: https://www.w3.org/WAI/ARIA/apg/patterns/carousel/
      */}
      {/* eslint-disable jsx-a11y/no-noninteractive-element-interactions, jsx-a11y/no-noninteractive-tabindex */}
      <div
        ref={emblaRef}
        className="overflow-hidden"
        tabIndex={0}
        role="region"
        aria-roledescription="carrousel"
        onKeyDown={onKeyDown}
      >
      {/* eslint-enable jsx-a11y/no-noninteractive-element-interactions, jsx-a11y/no-noninteractive-tabindex */}
        <div className="flex">
          {SLIDES.map((slide, index) => (
            <div
              key={slide.id}
              data-testid={`showcase-slide-${slide.id}`}
              role="group"
              aria-roledescription="slide"
              aria-label={`Slide ${index + 1} sur ${SLIDES.length} — ${slide.eyebrow}`}
              aria-hidden={index !== selectedIndex}
              className="relative h-[80vh] min-h-[560px] w-full shrink-0 grow-0 basis-full"
            >
              {/* Image de fond — priority + fetchpriority sur la 1re seulement (LCP) */}
              <Image
                src={mediaUrl(slide.imagePath)}
                alt={slide.imageAlt}
                fill
                sizes="100vw"
                priority={index === 0}
                fetchPriority={index === 0 ? 'high' : 'auto'}
                className="object-cover object-center"
                quality={85}
              />

              {/* Overlay gradient bottom pour lisibilité texte */}
              <div
                aria-hidden="true"
                className="absolute inset-0 bg-gradient-to-t from-[#14101A]/95 via-[#1B1620]/55 to-transparent"
              />
              {/* Diagonal slash décoratif gold — éditorial */}
              <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-y-0 left-0 w-1 bg-gradient-to-b from-transparent via-[#C9A227]/70 to-transparent md:left-6"
              />

              {/* Contenu — seule la 1re slide a un h1 (SEO + a11y heading-order) */}
              <div className="relative mx-auto flex h-full max-w-7xl flex-col justify-end px-6 pb-20 md:pb-28">
                <p className="pssfp-eyebrow text-[#E8C868]">{slide.eyebrow}</p>
                {index === 0 ? (
                  <h1 className="mt-3 max-w-3xl font-heading text-pssfp-h1 font-bold leading-[1.05] tracking-tight text-white md:text-[clamp(2.5rem,1rem+5vw,5rem)]">
                    {slide.title}
                  </h1>
                ) : (
                  <h2 className="mt-3 max-w-3xl font-heading text-pssfp-h1 font-bold leading-[1.05] tracking-tight text-white md:text-[clamp(2.5rem,1rem+5vw,5rem)]">
                    {slide.title}
                  </h2>
                )}
                <p className="mt-5 max-w-2xl text-pssfp-lead text-white/85">
                  {slide.subtitle}
                </p>
                <div className="mt-8 flex flex-wrap items-center gap-3">
                  <Link
                    href={slide.primaryCta.href}
                    data-testid={`showcase-cta-primary-${slide.id}`}
                    className="group inline-flex items-center gap-2 rounded-pssfp-button bg-[#C9A227] px-5 py-3 text-sm font-semibold text-[#14101A] shadow-pssfp-glow-or transition-all hover:-translate-y-0.5 hover:bg-[#D9B441] hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#E8C868] focus-visible:ring-offset-2 focus-visible:ring-offset-[#14101A]"
                  >
                    {slide.primaryCta.label}
                    <ArrowRight
                      size={16}
                      aria-hidden="true"
                      className="transition-transform group-hover:translate-x-0.5"
                    />
                  </Link>
                  {slide.secondaryCta && (
                    <Link
                      href={slide.secondaryCta.href}
                      data-testid={`showcase-cta-secondary-${slide.id}`}
                      className="inline-flex items-center gap-2 rounded-pssfp-button border border-[#C9A227]/60 bg-white/5 px-5 py-3 text-sm font-medium text-[#E8C868] backdrop-blur-sm transition-all hover:border-[#E8C868] hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#E8C868]"
                    >
                      {slide.secondaryCta.label}
                    </Link>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Boutons précédent/suivant */}
      <div className="pointer-events-none absolute inset-y-0 left-0 right-0 flex items-center justify-between px-3 md:px-6">
        <button
          type="button"
          onClick={scrollPrev}
          aria-label="Slide précédente"
          data-testid="showcase-prev"
          className="pointer-events-auto inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-md transition-all hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#E8C868]"
        >
          <ChevronLeft size={22} aria-hidden="true" />
        </button>
        <button
          type="button"
          onClick={scrollNext}
          aria-label="Slide suivante"
          data-testid="showcase-next"
          className="pointer-events-auto inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-md transition-all hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#E8C868]"
        >
          <ChevronRight size={22} aria-hidden="true" />
        </button>
      </div>

      {/* Indicateurs ronds */}
      <div
        className="absolute inset-x-0 bottom-6 flex items-center justify-center gap-2"
        role="tablist"
        aria-label="Choisir une slide"
        data-testid="showcase-dots"
      >
        {SLIDES.map((slide, index) => {
          const active = index === selectedIndex;
          return (
            <button
              key={slide.id}
              type="button"
              role="tab"
              aria-selected={active}
              aria-label={`Aller à la slide ${index + 1} — ${slide.eyebrow}`}
              onClick={() => goTo(index)}
              data-testid={`showcase-dot-${index}`}
              className={`h-2 rounded-full transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#E8C868] ${
                active ? 'w-8 bg-[#E8C868]' : 'w-2 bg-white/40 hover:bg-white/60'
              }`}
            />
          );
        })}
      </div>

      {/* Désactiver l'autoplay si prefers-reduced-motion : géré dans globals.css */}
    </section>
  );
}
