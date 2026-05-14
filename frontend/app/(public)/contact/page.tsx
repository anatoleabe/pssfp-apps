import { Mail, Phone, MapPin, Clock, ChevronDown } from 'lucide-react';
import type { Metadata } from 'next';
import { ContactForm } from '@/components/ContactForm';
import { GoogleMapEmbed } from '@/components/GoogleMapEmbed';

export const metadata: Metadata = {
  title: 'Contact',
  description:
    "Contactez le PSSFP — Campus de Messa, Yaoundé. Formulaire en ligne, adresse, téléphone et carte d'accès.",
};

interface ContactInfo {
  icon: typeof MapPin;
  label: string;
  primary: string;
  secondary: string;
  href?: string;
}

const CONTACT_INFOS: ContactInfo[] = [
  {
    icon: MapPin,
    label: 'Adresse',
    primary: 'Campus de Messa',
    secondary: 'Yaoundé, Cameroun',
  },
  {
    icon: Phone,
    label: 'Téléphone',
    primary: '+237 222 23 45 67',
    secondary: 'Lundi – Vendredi · 8h00 – 16h00',
    href: 'tel:+237222234567',
  },
  {
    icon: Mail,
    label: 'Email',
    primary: 'contact@pssfp.net',
    secondary: 'Réponse sous 48h ouvrées',
    href: 'mailto:contact@pssfp.net',
  },
];

interface FaqItem {
  q: string;
  a: string;
}

const FAQ: FaqItem[] = [
  {
    q: "Quels sont les horaires d'ouverture du secrétariat ?",
    a: "Le secrétariat est ouvert du lundi au vendredi, de 8h00 à 16h00. Nous sommes fermés les week-ends et jours fériés.",
  },
  {
    q: "Comment obtenir des informations sur les admissions ?",
    a: "Les conditions d'admission, le calendrier et la procédure de candidature sont détaillés sur la rubrique Candidature. Pour toute question spécifique, écrivez-nous via le formulaire ci-contre — réponse sous 48h ouvrées.",
  },
  {
    q: 'Puis-je visiter le campus avant de candidater ?',
    a: "Oui, des visites guidées du Campus de Messa sont organisées sur rendez-vous. Contactez le secrétariat pour planifier votre venue.",
  },
  {
    q: 'Comment contacter un département ou un responsable de spécialité ?',
    a: "Précisez le nom du département ou de la spécialité dans l'objet du message. Le secrétariat oriente votre demande vers la personne compétente.",
  },
];

export default function ContactPage(): JSX.Element {
  return (
    <>
      {/* ──────────────────────────────────────────────────────────────────
          Hero éditorial — eyebrow + headline Playfair + paragraphe + badge 48h
         ────────────────────────────────────────────────────────────────── */}
      <section
        aria-labelledby="contact-heading"
        className="relative isolate overflow-hidden bg-gradient-lavande-blanc dark:bg-[#14091F] dark:bg-none"
      >
        {/* Grain dotted décoratif (aria-hidden) */}
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-0 -z-10 opacity-[0.025]"
          style={{
            backgroundImage:
              'radial-gradient(circle at 1px 1px, #4A2E67 1px, transparent 0)',
            backgroundSize: '24px 24px',
          }}
        />
        {/* Halos colorés (aria-hidden) */}
        <div
          aria-hidden="true"
          className="pointer-events-none absolute -top-32 -right-32 -z-10 h-[480px] w-[480px] rounded-full bg-[#4A2E67] opacity-[0.06] blur-3xl"
        />
        <div
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-40 -left-32 -z-10 h-[420px] w-[420px] rounded-full bg-[#D4AF6A] opacity-[0.08] blur-3xl"
        />

        <div className="mx-auto max-w-4xl px-6 py-20 text-center md:py-28">
          <p className="pssfp-eyebrow">Contactez-nous</p>

          <h1
            id="contact-heading"
            className="mt-5 font-heading font-bold text-pssfp-h1 text-[#1A1A1A] dark:text-[#FAF7F2]"
          >
            Nous sommes à votre{' '}
            <span className="relative inline-block">
              <span className="pssfp-text-gradient-violet-or">écoute</span>
              <svg
                aria-hidden="true"
                className="absolute -bottom-2 left-0 w-full"
                height="10"
                viewBox="0 0 200 10"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
              >
                <path
                  d="M2 8C50 3 150 3 198 8"
                  stroke="#D4AF6A"
                  strokeWidth="3"
                  strokeLinecap="round"
                />
              </svg>
            </span>
          </h1>

          <p className="mx-auto mt-6 max-w-2xl pssfp-lead">
            Une question, un projet de partenariat, une demande d'information ?
            Le secrétariat du PSSFP vous répond — formulaire, téléphone, courriel
            ou visite sur le Campus de Messa à Yaoundé.
          </p>

          <div className="mt-8 flex items-center justify-center">
            <span className="inline-flex items-center gap-2 rounded-full border border-[#4A2E67]/20 bg-[#F4EFFA] px-4 py-1.5 text-sm font-medium text-[#4A2E67] dark:border-[#5C3A7E]/40 dark:bg-[#2A1E3A] dark:text-[#F4EFFA]">
              <Clock size={14} aria-hidden="true" />
              Réponse sous 48h ouvrées
            </span>
          </div>
        </div>
      </section>

      {/* ──────────────────────────────────────────────────────────────────
          Split — formulaire (gauche) + coordonnées cartes (droite)
         ────────────────────────────────────────────────────────────────── */}
      <section className="mx-auto max-w-7xl px-6 py-16 md:py-20">
        <div className="grid gap-12 lg:grid-cols-12 lg:gap-16">
          {/* Formulaire */}
          <div className="lg:col-span-7">
            <header className="mb-8">
              <p className="pssfp-eyebrow">Nous écrire</p>
              <h2 className="mt-3 font-heading text-pssfp-h2 font-bold text-[#1A1A1A] dark:text-[#FAF7F2]">
                Envoyez-nous un message
              </h2>
              <p className="mt-3 pssfp-body text-[#555] dark:text-[#C9C2D8]">
                Remplissez le formulaire ci-dessous. Les champs marqués <span aria-hidden="true">*</span> sont obligatoires.
              </p>
            </header>

            <div className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft dark:border-[#2A1E3A] dark:bg-[#1A1428] md:p-8">
              <ContactForm />
            </div>
          </div>

          {/* Coordonnées */}
          <aside aria-labelledby="info-heading" className="lg:col-span-5">
            <header className="mb-8">
              <p className="pssfp-eyebrow">Coordonnées</p>
              <h2
                id="info-heading"
                className="mt-3 font-heading text-pssfp-h2 font-bold text-[#1A1A1A] dark:text-[#FAF7F2]"
              >
                Où nous trouver
              </h2>
              <p className="mt-3 pssfp-body text-[#555] dark:text-[#C9C2D8]">
                Trois canaux pour nous joindre directement.
              </p>
            </header>

            <ul className="space-y-4">
              {CONTACT_INFOS.map((info) => (
                <li key={info.label}>
                  <ContactCard info={info} />
                </li>
              ))}
            </ul>

            {/* Séparateur ornement */}
            <div className="mt-10 flex items-center gap-4" aria-hidden="true">
              <div className="h-px flex-1 bg-gradient-to-r from-transparent to-[#D4AF6A]/40" />
              <span className="text-[#D4AF6A]">✦</span>
              <div className="h-px flex-1 bg-gradient-to-l from-transparent to-[#D4AF6A]/40" />
            </div>
          </aside>
        </div>
      </section>

      {/* ──────────────────────────────────────────────────────────────────
          Carte d'accès — pleine largeur
         ────────────────────────────────────────────────────────────────── */}
      <section
        aria-labelledby="map-heading"
        className="bg-gradient-lavande-blanc py-16 dark:bg-[#14091F] dark:bg-none md:py-20"
      >
        <div className="mx-auto max-w-7xl px-6">
          <header className="mx-auto mb-10 max-w-2xl text-center">
            <p className="pssfp-eyebrow">Accès</p>
            <h2
              id="map-heading"
              className="mt-3 font-heading text-pssfp-h2 font-bold text-[#1A1A1A] dark:text-[#FAF7F2]"
            >
              Campus de Messa, Yaoundé
            </h2>
            <p className="mt-3 pssfp-body text-[#555] dark:text-[#C9C2D8]">
              Repérez l'entrée principale et planifiez votre itinéraire.
            </p>
          </header>

          <div className="overflow-hidden rounded-pssfp-card border border-[#F4EFFA] bg-white shadow-pssfp-elevated dark:border-[#2A1E3A] dark:bg-[#1A1428]">
            <GoogleMapEmbed />
          </div>
        </div>
      </section>

      {/* ──────────────────────────────────────────────────────────────────
          FAQ — <details>/<summary> (server-component-safe, sans JS)
         ────────────────────────────────────────────────────────────────── */}
      <section
        aria-labelledby="faq-heading"
        className="mx-auto max-w-4xl px-6 py-16 md:py-20"
      >
        <header className="mb-10 text-center">
          <p className="pssfp-eyebrow inline-flex items-center justify-center gap-2">
            <span className="h-1.5 w-1.5 rounded-full bg-[#4A2E67]" aria-hidden="true" />
            FAQ
            <span className="h-1.5 w-1.5 rounded-full bg-[#D4AF6A]" aria-hidden="true" />
          </p>
          <h2
            id="faq-heading"
            className="mt-3 font-heading text-pssfp-h2 font-bold text-[#1A1A1A] dark:text-[#FAF7F2]"
          >
            Questions fréquentes
          </h2>
          <p className="mt-3 pssfp-body text-[#555] dark:text-[#C9C2D8]">
            Trouvez rapidement les réponses aux demandes les plus courantes.
          </p>
        </header>

        <ul className="space-y-3">
          {FAQ.map((item, i) => (
            <li key={item.q}>
              <details
                className="group rounded-pssfp-card border border-[#F4EFFA] bg-white px-5 py-1 shadow-pssfp-soft transition-shadow open:shadow-pssfp-elevated dark:border-[#2A1E3A] dark:bg-[#1A1428]"
                {...(i === 0 ? { open: true } : {})}
              >
                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 py-4 font-heading text-base font-semibold text-[#1A1A1A] outline-none transition-colors hover:text-[#4A2E67] focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 dark:text-[#FAF7F2] dark:hover:text-[#D4AF6A]">
                  <span>{item.q}</span>
                  <ChevronDown
                    size={18}
                    aria-hidden="true"
                    className="shrink-0 text-[#4A2E67] transition-transform duration-200 group-open:rotate-180 dark:text-[#D4AF6A]"
                  />
                </summary>
                <div className="pb-5 pr-8 text-sm leading-relaxed text-[#555] dark:text-[#C9C2D8]">
                  {item.a}
                </div>
              </details>
            </li>
          ))}
        </ul>

        {/* Ornement de clôture */}
        <div className="mt-12 flex items-center justify-center gap-4" aria-hidden="true">
          <div className="h-px w-20 bg-gradient-to-r from-transparent to-[#4A2E67]/40" />
          <div className="h-2 w-2 rounded-full bg-[#D4AF6A]" />
          <div className="h-px w-20 bg-gradient-to-l from-transparent to-[#4A2E67]/40" />
        </div>
      </section>
    </>
  );
}

function ContactCard({ info }: { info: ContactInfo }): JSX.Element {
  const Icon = info.icon;
  const inner = (
    <div className="group relative flex items-start gap-4 rounded-pssfp-card border border-[#F4EFFA] bg-white p-5 shadow-pssfp-soft transition-all duration-200 hover:-translate-y-0.5 hover:border-[#4A2E67]/30 hover:shadow-pssfp-elevated dark:border-[#2A1E3A] dark:bg-[#1A1428] dark:hover:border-[#5C3A7E]/40">
      <span
        aria-hidden="true"
        className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-prune-or text-white shadow-pssfp-glow-prune transition-transform duration-200 group-hover:scale-105"
      >
        <Icon size={20} />
      </span>
      <div className="min-w-0">
        <p className="font-heading text-sm font-semibold uppercase tracking-wider text-[#4A2E67] dark:text-[#D4AF6A]">
          {info.label}
        </p>
        <p className="mt-1 truncate font-heading text-base font-semibold text-[#1A1A1A] dark:text-[#FAF7F2]">
          {info.primary}
        </p>
        <p className="mt-0.5 text-sm text-[#555] dark:text-[#C9C2D8]">{info.secondary}</p>
      </div>
    </div>
  );

  return info.href ? (
    <a
      href={info.href}
      className="block rounded-pssfp-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
    >
      {inner}
    </a>
  ) : (
    inner
  );
}
