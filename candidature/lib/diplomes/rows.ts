/**
 * Lignes des blocs répétables « autres diplômes et formations ».
 *
 * `annee` accepte la chaîne vide pour représenter un champ numérique laissé
 * vide dans le DOM — la conversion en `number` a lieu à la saisie, jamais à
 * l'affichage.
 */
export interface AutreDiplomeRow {
  intitule: string;
  etablissement: string;
  annee: number | '';
}

export interface FormationProRow {
  centre: string;
  qualification: string;
  annee: number | '';
}

/** Aligné sur CandidatureDiplomeBlocks::MAX_ROWS côté backend. */
export const MAX_DIPLOME_ROWS = 10;

export const EMPTY_AUTRE_DIPLOME: AutreDiplomeRow = {
  intitule: '',
  etablissement: '',
  annee: '',
};

export const EMPTY_FORMATION_PRO: FormationProRow = {
  centre: '',
  qualification: '',
  annee: '',
};

export interface RepeatableColumn<T> {
  key: keyof T & string;
  /** Clé next-intl relative à `wizard.diplomes`. */
  labelKey: string;
  type: 'text' | 'year';
}

export const AUTRE_DIPLOME_COLUMNS: ReadonlyArray<RepeatableColumn<AutreDiplomeRow>> = [
  { key: 'intitule', labelKey: 'autresDiplomes.intitule', type: 'text' },
  { key: 'etablissement', labelKey: 'autresDiplomes.etablissement', type: 'text' },
  { key: 'annee', labelKey: 'autresDiplomes.annee', type: 'year' },
];

export const FORMATION_PRO_COLUMNS: ReadonlyArray<RepeatableColumn<FormationProRow>> = [
  { key: 'centre', labelKey: 'formationsPro.centre', type: 'text' },
  { key: 'qualification', labelKey: 'formationsPro.qualification', type: 'text' },
  { key: 'annee', labelKey: 'formationsPro.annee', type: 'year' },
];

/** Une ligne vide n'est ni envoyée au backend ni soumise à validation. */
export function isRowEmpty<T extends object>(row: T): boolean {
  return Object.values(row).every(
    (value) => value === '' || value === null || value === undefined,
  );
}
