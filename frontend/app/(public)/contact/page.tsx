import { Mail, Phone, MapPin } from 'lucide-react';
import type { Metadata } from 'next';
import { ContactForm } from '@/components/ContactForm';
import { GoogleMapEmbed } from '@/components/GoogleMapEmbed';

export const metadata: Metadata = {
  title: 'Contact',
  description: 'Contactez le PSSFP — Campus de Messa, Yaoundé. Formulaire en ligne, adresse, téléphone et carte d\'accès.',
};

export default function ContactPage(): JSX.Element {
  return (
    <div className="mx-auto max-w-6xl px-6 py-12 md:py-16">
      <header className="mb-10 max-w-2xl">
        <h1 className="font-heading text-3xl font-bold text-[#6B2FA0] md:text-4xl">Contact</h1>
        <p className="mt-3 text-lg text-[#555]">
          Une question, un projet de partenariat, une demande d'information ? Notre équipe vous répond.
        </p>
      </header>

      <div className="grid gap-10 lg:grid-cols-[2fr_1fr]">
        <section aria-labelledby="form-heading">
          <h2 id="form-heading" className="font-heading text-xl font-bold text-[#333]">
            Nous écrire
          </h2>
          <p className="mt-2 text-sm text-[#555]">
            Réponse sous 48h ouvrées. Les champs marqués * sont obligatoires.
          </p>
          <div className="mt-6">
            <ContactForm />
          </div>
        </section>

        <aside aria-labelledby="info-heading" className="space-y-6">
          <section>
            <h2 id="info-heading" className="font-heading text-xl font-bold text-[#333]">
              Coordonnées
            </h2>
            <ul className="mt-4 space-y-3 text-sm text-[#333]">
              <li className="flex items-start gap-3">
                <MapPin size={16} className="mt-0.5 shrink-0 text-[#6B2FA0]" aria-hidden="true" />
                <div>
                  <p className="font-semibold">Campus de Messa</p>
                  <p className="text-[#555]">Yaoundé, Cameroun</p>
                </div>
              </li>
              <li className="flex items-start gap-3">
                <Phone size={16} className="mt-0.5 shrink-0 text-[#6B2FA0]" aria-hidden="true" />
                <a href="tel:+237222234567" className="hover:text-[#6B2FA0]">
                  +237 222 23 45 67
                </a>
              </li>
              <li className="flex items-start gap-3">
                <Mail size={16} className="mt-0.5 shrink-0 text-[#6B2FA0]" aria-hidden="true" />
                <a href="mailto:contact@pssfp.net" className="hover:text-[#6B2FA0]">
                  contact@pssfp.net
                </a>
              </li>
            </ul>
          </section>

          <section aria-label="Carte d'accès">
            <GoogleMapEmbed />
          </section>
        </aside>
      </div>
    </div>
  );
}
