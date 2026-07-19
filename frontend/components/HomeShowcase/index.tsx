'use client';

/**
 * HomeShowcase — carousel hero 5 slides (cf. spec sprint S5 PR Y, refonte S5.3).
 *
 * S5.3 — Rendu visuel épuré : photo plein cadre + voile plat +
 * eyebrow or + titre Cormorant XL + 2 badges-cards translucides bordure or
 * dont les icônes s'adaptent à chaque slide. Pas de top bar / bottom bar
 * institutionnels — l'identité PSSFP est portée par le SiteHeader global.
 *
 * Scaffolding Embla, navigation clavier, autoplay, reduced-motion : INCHANGÉS.
 */
import Image from 'next/image';
import Link from 'next/link';
import useEmblaCarousel from 'embla-carousel-react';
import Autoplay from 'embla-carousel-autoplay';
import { useCallback, useEffect, useState } from 'react';
import {
  Award,
  BadgeCheck,
  BookOpen,
  Building2,
  ChevronLeft,
  ChevronRight,
  Globe2,
  GraduationCap,
  Handshake,
  Layers,
  type LucideIcon,
} from 'lucide-react';
import { mediaUrl } from '../../lib/media';

interface ShowcaseCta {
  label: string;
  href: string;
  /** Icône Lucide rendue dans le badge — choisie pour le thème de la slide. */
  icon: LucideIcon;
}

export interface ShowcaseSlide {
  /** Identifiant stable pour les listes / tests. */
  id: string;
  /** Eyebrow court (ex. "Identité institutionnelle"). */
  eyebrow: string;
  /** Titre principal Cormorant Garamond (peut contenir un span de mise en valeur). */
  title: string;
  /** Sous-titre Source Sans 3. */
  subtitle: string;
  /** Chemin relatif au bucket pssfp-media (ex. "photos/evenements/dsc-0538.webp"). */
  imagePath: string;
  /** Texte alt pour l'image. */
  imageAlt: string;
  /** CTA principal (rendu badge-card avec icône thématique). */
  primaryCta: ShowcaseCta;
  /** CTA secondaire optionnel (rendu badge-card avec icône thématique). */
  secondaryCta?: ShowcaseCta;
}

const SLIDES: ReadonlyArray<ShowcaseSlide> = [
  {
    id: 'identite',
    eyebrow: 'PSSFP — depuis 2013',
    title: "Former l'élite des finances publiques",
    subtitle:
      "Une institution d'excellence au service de la modernisation de l'action publique du Cameroun et de la sous-région CEMAC.",
    imagePath: 'photos/slidershow/slidershow1-pssfp1.webp',
    imageAlt: 'Cérémonie de remise des diplômes — Promotion 2025 du PSSFP',
    primaryCta: { label: 'Excellence académique', href: '/formations', icon: Award },
    secondaryCta: { label: 'Promotion 2025', href: '#candidature', icon: GraduationCap },
  },
  {
    id: 'excellence-promotions',
    eyebrow: '13 promotions diplômées',
    title: '13 promotions au service de l’État',
    subtitle:
      '5 spécialités du Master Professionnel en Finances Publiques — BAC+5 reconnu CAMES, débouchés DGI, DGD, DGTCFM, MINEPAT, ARMP, FEICOM.',
    imagePath: 'photos/evenements/dsc-0466.webp',
    imageAlt: 'Diplômés de la sortie solennelle promo 6',
    primaryCta: { label: 'Master Professionnel', href: '/formations/master', icon: GraduationCap },
    secondaryCta: { label: '5 spécialités CAMES', href: '/formations/master', icon: BadgeCheck },
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
      label: 'Catalogue 2026',
      href: '/formations/formation-continue',
      icon: BookOpen,
    },
    secondaryCta: {
      label: '10 modules courts',
      href: '/formations/formation-continue',
      icon: Layers,
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
    primaryCta: { label: 'Notre gouvernance', href: '/a-propos', icon: Building2 },
    secondaryCta: { label: 'MINFI · MINESUP · UY2', href: '/a-propos', icon: Handshake },
  },
  {
    id: 'international',
    eyebrow: 'Coopération internationale',
    title: 'Une école ouverte sur le monde',
    subtitle:
      'Coopération CEMAC, partenariats avec la France (Expertise France, ENA) et le Maroc (Institut des Finances Basil Fuleihan), certifications internationales (FMI, OCDE).',
    imagePath: 'photos/evenements/whatsapp-image-2025-10-04-at-192408.webp',
    imageAlt: 'Délégation PSSFP en mission internationale',
    primaryCta: { label: 'Nos partenaires', href: '/a-propos/partenaires', icon: Globe2 },
    secondaryCta: { label: 'CEMAC · FMI · OCDE', href: '/a-propos/partenaires', icon: Handshake },
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
      className="relative isolate overflow-hidden bg-[#0A1B2E]"
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
              className="relative h-[70vh] min-h-[520px] w-full shrink-0 grow-0 basis-full md:h-[78vh] md:min-h-[580px] md:max-h-[760px]"
            >
              {/*
                Fond plat de secours : ne jamais laisser de vide noir tant que
                MinIO charge (S5.1, audit P1 #4).
              */}
              <div
                aria-hidden="true"
                className="absolute inset-0 -z-10 bg-[#0A1B2E]"
              />

              {/*
                Image de fond plein cadre — priority + fetchPriority sur la 1re
                pour optimiser le LCP. Utilise les variantes WebP générées par
                AssetImportService (full ≤ 1920px, compression qualité 85).
              */}
              <Image
                src={mediaUrl(slide.imagePath)}
                alt={slide.imageAlt}
                fill
                sizes="100vw"
                priority={index === 0}
                fetchPriority={index === 0 ? 'high' : 'auto'}
                loading={index === 0 ? 'eager' : 'lazy'}
                className="object-cover object-center"
                quality={85}
              />

              {/*
                S5.3 — Voile plat pour la lisibilité du texte sur la photo.
                Différenciateur visuel majeur de la nouvelle maquette.
              */}
              <div
                aria-hidden="true"
                className="absolute inset-0 bg-[#0A1B2E]/70"
              />

              {/*
                Contenu textuel — centré verticalement (items-center).
                Pas de padding vertical : sans top/bottom bars à compenser,
                le contenu doit occuper sereinement le centre du slide.
              */}
              <div className="relative mx-auto flex h-full max-w-7xl items-center px-6 md:px-10">
                <div className="max-w-3xl">
                  {/* Eyebrow avec trait or à gauche */}
                  <div className="flex items-center gap-3">
                    <span aria-hidden="true" className="h-px w-10 bg-[#D4AF6A] md:w-14" />
                    <p className="font-ui text-[11px] font-medium uppercase tracking-[0.28em] text-[#D4AF6A] md:text-xs">
                      {slide.eyebrow}
                    </p>
                  </div>

                  {/* Titre — h1 uniquement sur la 1re slide (SEO + a11y heading-order) */}
                  {index === 0 ? (
                    <h1 className="mt-5 font-heading text-[clamp(2.5rem,1rem+5vw,5.5rem)] font-bold leading-[1.04] text-white md:mt-7">
                      {slide.title}
                    </h1>
                  ) : (
                    <h2 className="mt-5 font-heading text-[clamp(2.5rem,1rem+5vw,5.5rem)] font-bold leading-[1.04] text-white md:mt-7">
                      {slide.title}
                    </h2>
                  )}

                  <p className="mt-5 max-w-xl text-lg leading-relaxed text-white/85 md:mt-7 md:text-xl">
                    {slide.subtitle}
                  </p>

                  {/*
                    Badges-cards — chaque slide fournit ses icônes thématiques
                    (Award, GraduationCap, BookOpen, BadgeCheck, Building2,
                    Handshake, Globe2, Layers) pour rester en phase avec son
                    sujet. Cf. tableau SLIDES.
                  */}
                  <div className="mt-8 flex flex-wrap items-center gap-3 md:mt-10 md:gap-4">
                    {(() => {
                      const PrimaryIcon = slide.primaryCta.icon;
                      return (
                        <Link
                          href={slide.primaryCta.href}
                          data-testid={`showcase-cta-primary-${slide.id}`}
                          className="group inline-flex items-center gap-3 rounded-lg border border-[#D4AF6A]/40 bg-[#0F3A4A]/60 px-5 py-3 backdrop-blur-sm transition-all hover:-translate-y-0.5 hover:border-[#D4AF6A] hover:bg-[#0F3A4A]/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0A1B2E]"
                        >
                          <span className="flex h-10 w-10 items-center justify-center rounded-full bg-[#D4AF6A]/15 transition-colors group-hover:bg-[#D4AF6A]/25">
                            <PrimaryIcon
                              size={20}
                              className="text-[#D4AF6A]"
                              aria-hidden="true"
                            />
                          </span>
                          <span className="font-ui text-sm font-medium text-white">
                            {slide.primaryCta.label}
                          </span>
                        </Link>
                      );
                    })()}
                    {slide.secondaryCta &&
                      (() => {
                        const SecondaryIcon = slide.secondaryCta.icon;
                        return (
                          <Link
                            href={slide.secondaryCta.href}
                            data-testid={`showcase-cta-secondary-${slide.id}`}
                            className="group inline-flex items-center gap-3 rounded-lg border border-[#D4AF6A]/40 bg-[#0F3A4A]/60 px-5 py-3 backdrop-blur-sm transition-all hover:-translate-y-0.5 hover:border-[#D4AF6A] hover:bg-[#0F3A4A]/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0A1B2E]"
                          >
                            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-[#D4AF6A]/15 transition-colors group-hover:bg-[#D4AF6A]/25">
                              <SecondaryIcon
                                size={20}
                                className="text-[#D4AF6A]"
                                aria-hidden="true"
                              />
                            </span>
                            <span className="font-ui text-sm font-medium text-white">
                              {slide.secondaryCta.label}
                            </span>
                          </Link>
                        );
                      })()}
                  </div>
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
          className="pointer-events-auto inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-md transition-all hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A]"
        >
          <ChevronLeft size={22} aria-hidden="true" />
        </button>
        <button
          type="button"
          onClick={scrollNext}
          aria-label="Slide suivante"
          data-testid="showcase-next"
          className="pointer-events-auto inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-md transition-all hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A]"
        >
          <ChevronRight size={22} aria-hidden="true" />
        </button>
      </div>

      {/* Indicateurs ronds — ancrés en bas du slide. */}
      <div
        className="absolute inset-x-0 bottom-6 z-20 flex items-center justify-center gap-2 md:bottom-8"
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
              className={`h-2 rounded-full transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0A1B2E] ${
                active ? 'w-8 bg-[#D4AF6A]' : 'w-2 bg-white/40 hover:bg-white/60'
              }`}
            />
          );
        })}
      </div>

      {/* Désactiver l'autoplay si prefers-reduced-motion : géré dans globals.css */}
    </section>
  );
}
