<?php

declare(strict_types=1);

/*
 * Liste blanche des spécialités du PSSFP pour V1 (cf. spec module 5 §M1 + CDC v5 §A.3).
 *
 * Intitulés exacts du communiqué officiel d'appel à candidature P14 (signé
 * Ministre des Finances + Recteur UY2-Soa, N°26/…/C/MINFI/SG/PSSFP/PCP/PCS/
 * UPAAS/AT) — la valeur (label) est ce qui est réellement stocké/validé sur
 * `candidatures.specialite` (cf. UpdateCandidatureRequest, wizard frontend
 * WizardStep1Identite qui envoie le label, pas la clé). Le candidat DOIT
 * pouvoir sélectionner exactement l'intitulé du communiqué signé.
 *
 * V1 utilise une config statique pour ne pas coupler module 5 (candidatures)
 * et module 6 (CMS Filament). Quand le module 6 introduira une table
 * `specialites` administrable, cette liste deviendra le seed initial et la
 * source devra basculer (via SpecialiteService::active()).
 *
 * Le wizard frontend lit cette liste via GET /v1/reference/specialites
 * (ou inline dans le schema OpenAPI). À mettre dans /v1/reference/* PR C.
 */
return [
    'economie-publique-gestion-publique' => 'Économie Publique et Gestion Publique',
    'fiscalite-finance-comptabilite-publique' => 'Fiscalité, Finance et Comptabilité Publique',
    'gouvernance-territoriale-finances-publiques-locales' => 'Gouvernance Territoriale et Finances Publiques locales',
    'marches-publics-partenariats-public-prive' => 'Marchés Publics et Partenariats Public-Privés',
    'audit-controle-finances-publiques' => 'Audit et contrôle des Finances Publiques',
];
