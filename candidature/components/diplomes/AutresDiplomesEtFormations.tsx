'use client';

import { useTranslations } from 'next-intl';

import { RepeatableRows } from '@/components/RepeatableRows';
import {
  AUTRE_DIPLOME_COLUMNS,
  EMPTY_AUTRE_DIPLOME,
  EMPTY_FORMATION_PRO,
  FORMATION_PRO_COLUMNS,
  MAX_DIPLOME_ROWS,
  type AutreDiplomeRow,
  type FormationProRow,
  type RepeatableColumn,
} from '@/lib/diplomes/rows';

export interface AutresDiplomesEtFormationsProps {
  autresDiplomes: readonly AutreDiplomeRow[];
  formationsProfessionnelles: readonly FormationProRow[];
  errors: Record<string, string | undefined>;
  onChangeAutresDiplomes: (rows: AutreDiplomeRow[]) => void;
  onChangeFormations: (rows: FormationProRow[]) => void;
}

/**
 * Section facultative « Autres diplômes et formations » — deux blocs répétables
 * indépendants. Partagée par le wizard d'inscription et l'édition du dossier :
 * une seule source de vérité pour le balisage, les libellés et l'accessibilité.
 */
export function AutresDiplomesEtFormations({
  autresDiplomes,
  formationsProfessionnelles,
  errors,
  onChangeAutresDiplomes,
  onChangeFormations,
}: AutresDiplomesEtFormationsProps): JSX.Element {
  const t = useTranslations('wizard.diplomes');

  return (
    <section className="space-y-4" data-testid="autres-diplomes-section">
      <div>
        <h3 className="font-heading text-lg font-semibold text-[#4A2E67]">{t('sectionTitle')}</h3>
        <p className="text-xs text-[#666]">{t('sectionHint')}</p>
      </div>

      <RepeatableRows<AutreDiplomeRow>
        legend={t('autresDiplomes.legend')}
        addLabel={t('autresDiplomes.add')}
        removeLabel={(position) => t('autresDiplomes.remove', { index: position })}
        addedAnnouncement={t('autresDiplomes.added')}
        removedAnnouncement={t('autresDiplomes.removed')}
        maxReachedMessage={t('autresDiplomes.max', { max: MAX_DIPLOME_ROWS })}
        columns={AUTRE_DIPLOME_COLUMNS}
        columnLabel={(column: RepeatableColumn<AutreDiplomeRow>) => t(column.labelKey)}
        rows={autresDiplomes}
        emptyRow={EMPTY_AUTRE_DIPLOME}
        max={MAX_DIPLOME_ROWS}
        fieldName="autres_diplomes"
        errors={errors}
        testIdPrefix="autres-diplomes"
        onChange={onChangeAutresDiplomes}
      />

      <RepeatableRows<FormationProRow>
        legend={t('formationsPro.legend')}
        addLabel={t('formationsPro.add')}
        removeLabel={(position) => t('formationsPro.remove', { index: position })}
        addedAnnouncement={t('formationsPro.added')}
        removedAnnouncement={t('formationsPro.removed')}
        maxReachedMessage={t('formationsPro.max', { max: MAX_DIPLOME_ROWS })}
        columns={FORMATION_PRO_COLUMNS}
        columnLabel={(column: RepeatableColumn<FormationProRow>) => t(column.labelKey)}
        rows={formationsProfessionnelles}
        emptyRow={EMPTY_FORMATION_PRO}
        max={MAX_DIPLOME_ROWS}
        fieldName="formations_professionnelles"
        errors={errors}
        testIdPrefix="formations-pro"
        onChange={onChangeFormations}
      />
    </section>
  );
}
