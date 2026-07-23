import Link from 'next/link';
import { Pencil, UserRoundSearch } from 'lucide-react';
import type { MyCandidature } from '@/lib/api/client';
import { formatDateFr } from '@/lib/format/date';
import { STATUT_ACTUEL_OPTIONS } from '@/lib/dossier/options';

export function DossierProfileSummary({
  candidature,
}: {
  candidature: MyCandidature;
}): JSX.Element {
  const canEdit = candidature.statut === 'postulant' && candidature.withdrawn_at === null;
  const statusLabel =
    STATUT_ACTUEL_OPTIONS.find((option) => option.value === candidature.statut_actuel)?.label ??
    candidature.statut_actuel;

  return (
    <section
      aria-labelledby="profile-summary-heading"
      className="rounded-pssfp-card border border-[#E4DCEE] bg-white p-6 shadow-pssfp-soft md:p-7"
      data-testid="dossier-profile-summary"
    >
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="pssfp-eyebrow inline-flex items-center gap-2">
            <UserRoundSearch size={14} aria-hidden="true" />
            Informations enregistrées
          </p>
          <h2
            id="profile-summary-heading"
            className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]"
          >
            Consulter mon dossier
          </h2>
          <p className="mt-2 max-w-2xl text-sm leading-relaxed text-[#595959]">
            Relisez régulièrement vos informations. Les corrections restent possibles jusqu’à la
            soumission définitive de la candidature.
          </p>
        </div>
        {canEdit && (
          <Link
            href="/dossier/edition"
            data-testid="dossier-profile-edit"
            className="inline-flex h-11 items-center gap-2 rounded-pssfp-button bg-[#4A2E67] px-4 text-sm font-semibold text-white shadow-pssfp-elevated hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A] focus-visible:ring-offset-2"
          >
            <Pencil size={16} aria-hidden="true" />
            Modifier mes informations
          </Link>
        )}
      </div>

      <div className="mt-6 grid gap-5 lg:grid-cols-2">
        <ProfileGroup
          title="Identité et coordonnées"
          editHref={canEdit ? '/dossier/edition?focus=civilite' : null}
          rows={[
            ['Nom complet', joinValues(candidature.civilite, candidature.prenom, candidature.nom)],
            ['Date de naissance', candidature.date_naissance ? formatDateFr(candidature.date_naissance) : null],
            ['Lieu de naissance', candidature.lieu_naissance],
            ['Adresse e-mail', candidature.email],
            ['Téléphone', joinValues(candidature.indicatif1, candidature.telephone1)],
            ['Résidence', joinValues(candidature.adresse, candidature.ville_residence)],
          ]}
        />
        <ProfileGroup
          title="Formation demandée"
          editHref={canEdit ? '/dossier/edition?focus=specialite' : null}
          rows={[
            ['Spécialité', candidature.specialite],
            [
              'Modalité',
              candidature.type_etude === 'presentiel'
                ? 'Présentiel'
                : candidature.type_etude === 'distanciel'
                  ? 'Distanciel'
                  : null,
            ],
            [
              'Première langue',
              candidature.premiere_langue === 'fr'
                ? 'Français'
                : candidature.premiere_langue === 'en'
                  ? 'Anglais'
                  : null,
            ],
          ]}
        />
        <ProfileGroup
          title="Parcours académique"
          editHref={canEdit ? '/dossier/edition?focus=diplome_obtenu' : null}
          rows={[
            ['Diplôme', candidature.diplome_obtenu],
            ['Établissement', candidature.institut],
            ['Spécialité du diplôme', candidature.specialite_diplome],
            ['Année d’obtention', candidature.annee_diplome ? String(candidature.annee_diplome) : null],
          ]}
        />
        <ProfileGroup
          title="Situation professionnelle"
          editHref={canEdit ? '/dossier/edition?focus=statut_actuel' : null}
          rows={[
            ['Situation actuelle', statusLabel],
            ['Fonction', candidature.fonction_actuelle],
            ['Employeur', candidature.employeur],
            ['Comment vous avez connu le PSSFP', candidature.moyen_connaissance],
          ]}
        />
      </div>

      {!canEdit && (
        <p className="mt-5 rounded-md bg-gray-50 p-3 text-sm text-[#595959]">
          Ce récapitulatif reste consultable, mais le dossier est verrouillé depuis sa soumission.
        </p>
      )}
    </section>
  );
}

function ProfileGroup({
  title,
  editHref,
  rows,
}: {
  title: string;
  editHref: string | null;
  rows: Array<[string, string | null | undefined]>;
}): JSX.Element {
  return (
    <section className="rounded-lg border border-[#F0EAF6] bg-[#FCFAFE] p-4">
      <div className="flex items-center justify-between gap-3">
        <h3 className="font-heading font-bold text-[#4A2E67]">{title}</h3>
        {editHref && (
          <Link href={editHref} className="text-xs font-semibold text-[#4A2E67] underline">
            Modifier
          </Link>
        )}
      </div>
      <dl className="mt-3 space-y-2 text-sm">
        {rows.map(([label, value]) => (
          <div key={label} className="grid gap-0.5 sm:grid-cols-[9rem_1fr] sm:gap-3">
            <dt className="text-[#777]">{label}</dt>
            <dd className="break-words font-medium text-[#292929]">{value || 'Non renseigné'}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

function joinValues(...values: Array<string | null | undefined>): string | null {
  const result = values.map((value) => value?.trim()).filter(Boolean).join(' ');
  return result || null;
}
