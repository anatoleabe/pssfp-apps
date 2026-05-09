import { z } from 'zod';

const phoneE164Regex = /^\+[1-9]\d{6,14}$/;
const pinRegex = /^\d{6}$/;
const isoCountryRegex = /^[A-Z]{2}$/;

const minAgeYears = 18;
const eighteenYearsAgoIso = (() => {
  const now = new Date();
  const d = new Date(now.getFullYear() - minAgeYears, now.getMonth(), now.getDate());
  return d.toISOString().slice(0, 10);
})();

export const step1Schema = z.object({
  specialite: z.string().min(1, 'Spécialité obligatoire'),
  type_etude: z.enum(['presentiel', 'distanciel']),
  premiere_langue: z.enum(['fr', 'en']),
  civilite: z.enum(['M.', 'Mme', 'Mlle']),
  nom: z.string().trim().min(1).max(100),
  prenom: z.string().trim().min(1).max(100),
  epouse: z.string().trim().max(100).optional().nullable(),
  date_naissance: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/, 'Date au format YYYY-MM-DD')
    .refine((v) => v <= eighteenYearsAgoIso, 'Vous devez avoir au moins 18 ans'),
  genre: z.enum(['M', 'F', 'autre']),
  statut_matrimonial: z.string().trim().min(1).max(20),
  nationalite: z.string().regex(isoCountryRegex, 'Code pays ISO-2 attendu'),
});

export const step2Schema = z
  .object({
    pays_origine: z.string().regex(isoCountryRegex),
    pays_residence: z.string().regex(isoCountryRegex),
    region: z.string().trim().optional().nullable(),
    departement: z.string().trim().optional().nullable(),
    adresse: z.string().trim().min(1).max(200),
    ville_residence: z.string().trim().min(1).max(100),
    lieu_naissance: z.string().trim().min(1).max(100),
    indicatif1: z.string().trim().min(1).max(10),
    telephone1: z.string().trim().min(1).max(20),
    phone_e164: z.string().regex(phoneE164Regex, 'Numéro E.164 invalide'),
    indicatif2: z.string().trim().max(10).optional().nullable(),
    telephone2: z.string().trim().max(20).optional().nullable(),
    email: z.string().email('Email invalide').optional().or(z.literal('')).nullable(),
  })
  .refine(
    (data) => data.pays_residence !== 'CM' || (!!data.region && !!data.departement),
    {
      message: 'Région et département obligatoires pour un candidat résidant au Cameroun',
      path: ['region'],
    },
  );

export const step3Schema = z.object({
  diplome_obtenu: z.string().trim().min(1).max(100),
  institut: z.string().trim().min(1).max(150),
  specialite_diplome: z.string().trim().min(1).max(100),
  annee_diplome: z
    .number({ error: 'Année invalide' })
    .int()
    .min(1950)
    .max(new Date().getFullYear()),
  statut_actuel: z.enum(['Etudiant', 'Fonctionnaire-Contractuel', 'Prive']),
  employeur: z.string().trim().max(150).optional().nullable(),
  adresse_employeur: z.string().trim().max(200).optional().nullable(),
  tel_employeur: z.string().trim().max(30).optional().nullable(),
  moyen_connaissance: z.string().trim().max(50).optional().nullable(),
});

export const step4Schema = z
  .object({
    engagement_nom: z.string().trim().min(1).max(200),
    pin: z.string().regex(pinRegex, 'PIN à 6 chiffres requis'),
    pin_confirmation: z.string().regex(pinRegex),
    cgu: z.literal(true, { error: 'Vous devez accepter les CGU' }),
  })
  .refine((data) => data.pin === data.pin_confirmation, {
    message: 'La confirmation du PIN ne correspond pas',
    path: ['pin_confirmation'],
  });

export const fullWizardSchema = step1Schema.and(step2Schema).and(step3Schema).and(step4Schema);

export type Step1Values = z.infer<typeof step1Schema>;
export type Step2Values = z.infer<typeof step2Schema>;
export type Step3Values = z.infer<typeof step3Schema>;
export type Step4Values = z.infer<typeof step4Schema>;
export type WizardValues = z.infer<typeof fullWizardSchema>;
