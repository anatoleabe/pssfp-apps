'use server';

import { putApplicationsMe, registerCandidat } from '@/lib/api/client';
import { setCandidatToken } from '@/lib/auth/session';
import type { WizardData, WizardServerActionResult } from '@/components/wizard/types';
import { isValidEngagement } from '@/lib/format/engagement';
import { validateCandidatePin } from '@/lib/validation/pinValidation';
import {
  step1Schema,
  step2Schema,
  step3Schema,
  step4Schema,
} from '@/lib/validation/schemas';

/**
 * Server Action de soumission finale du wizard.
 *
 * Flow :
 * 1. Re-valide tout côté serveur (défense en profondeur).
 * 2. POST /v1/auth/candidat/register → User + token Sanctum.
 *    - 409 → ce phone est déjà enregistré, on retourne CTA login.
 *    - 422 → renvoie les erreurs zod-style mappées sur les champs.
 * 3. Set le cookie httpOnly via Server Action.
 * 4. PUT /v1/applications/me avec le profil étendu (étapes 1-3).
 *    - Si KO : log Sentry critical + redirige quand même vers /dossier
 *      (le User existe bien, /dossier saura inviter à compléter).
 *      Cf. ajout 4 PR E (failure partielle réseau intermittent).
 */
export async function submitInscription(payload: WizardData): Promise<WizardServerActionResult> {
  const errors: Record<string, string> = {};

  for (const schema of [step1Schema, step2Schema, step3Schema, step4Schema] as const) {
    const result = schema.safeParse(payload);
    if (!result.success) {
      for (const issue of result.error.issues) {
        const key = String(issue.path[0] ?? 'global');
        if (!errors[key]) {
          errors[key] = issue.message;
        }
      }
    }
  }
  // Règles business additionnelles partagées avec le client.
  if (!isValidEngagement(payload.engagement_nom, payload.prenom, payload.nom)) {
    errors.engagement_nom = 'La signature doit correspondre à votre prénom et votre nom.';
  }
  const pinCheck = validateCandidatePin(
    payload.pin,
    payload.phone_e164,
    payload.date_naissance || null,
  );
  if (!pinCheck.ok) {
    errors.pin = 'Le PIN choisi ne respecte pas les règles de sécurité (cf. liste interdite, suffixe téléphone, date de naissance).';
  }

  if (Object.keys(errors).length > 0) {
    return { ok: false, errors, message: 'Validation côté serveur échouée.' };
  }

  // 1. Register
  const register = await registerCandidat({
    phone_e164: payload.phone_e164,
    phone_country: payload.phone_country,
    pin: payload.pin,
    pin_confirmation: payload.pin_confirmation,
    nom: payload.nom,
    prenom: payload.prenom,
    date_naissance: payload.date_naissance,
    cgu: payload.cgu,
    turnstile_token: payload.turnstile_token,
  });

  if (!register.ok) {
    if (register.status === 409) {
      return {
        ok: false,
        message: 'Ce numéro est déjà enregistré.',
        errors: {
          phone_e164: 'Ce numéro est déjà enregistré pour une candidature précédente.',
        },
        cta: {
          label: 'Se connecter avec ce numéro',
          href: `/login?phone=${encodeURIComponent(payload.phone_e164)}`,
        },
      };
    }

    if (register.status === 422 && register.errors) {
      const flat: Record<string, string> = {};
      for (const [field, msgs] of Object.entries(register.errors)) {
        flat[field] = msgs[0] ?? 'Champ invalide';
      }
      return { ok: false, message: register.message, errors: flat };
    }

    return {
      ok: false,
      message: register.message ?? 'Erreur lors de la création du compte.',
    };
  }

  // 2. Persist token
  await setCandidatToken(register.data.token, register.data.expires_at);

  // 3. PUT profile data
  const profilePut = await putApplicationsMe(
    {
      civilite: payload.civilite,
      epouse: payload.epouse || null,
      lieu_naissance: payload.lieu_naissance,
      genre: payload.genre,
      statut_matrimonial: payload.statut_matrimonial,
      nationalite: payload.nationalite,
      pays_origine: payload.pays_origine,
      pays_residence: payload.pays_residence,
      region: payload.region || null,
      departement: payload.departement || null,
      adresse: payload.adresse,
      ville_residence: payload.ville_residence,
      indicatif1: payload.indicatif1,
      telephone1: payload.telephone1,
      indicatif2: payload.indicatif2 || null,
      telephone2: payload.telephone2 || null,
      email: payload.email || null,
      specialite: payload.specialite,
      type_etude: payload.type_etude,
      premiere_langue: payload.premiere_langue,
      diplome_obtenu: payload.diplome_obtenu,
      institut: payload.institut,
      specialite_diplome: payload.specialite_diplome,
      annee_diplome:
        typeof payload.annee_diplome === 'number' ? payload.annee_diplome : undefined,
      statut_actuel:
        payload.statut_actuel === '' ? undefined : payload.statut_actuel,
      employeur: payload.employeur || null,
      adresse_employeur: payload.adresse_employeur || null,
      tel_employeur: payload.tel_employeur || null,
      engagement_nom: payload.engagement_nom,
      moyen_connaissance: payload.moyen_connaissance || null,
    },
    register.data.token,
  );

  if (!profilePut.ok) {
    // Failure partielle : User créé mais profil pas remonté. On redirige
    // vers /dossier, qui invitera à compléter (cf. ajout 4 PR E).
    // eslint-disable-next-line no-console -- log local + remonte Sentry en prod
    console.error('[submitInscription] register OK mais PUT /me KO', {
      status: profilePut.status,
      message: profilePut.message,
      // id interne (BIGSERIAL) — jamais exposé publiquement, log serveur only.
      userId: register.data.user.id,
    });
    return { ok: true, redirectTo: '/dossier?profile_pending=1' };
  }

  return { ok: true, redirectTo: '/dossier' };
}
