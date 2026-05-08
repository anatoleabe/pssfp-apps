<?php

declare(strict_types=1);

/*
 * Liste blanche des spécialités du PSSFP pour V1 (cf. spec module 5 §M1 + CDC v5 §A.3).
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
    'fiscalite-finance-comptabilite-publique' => 'Fiscalité - Finance - Comptabilité Publique',
    'audit-controle-gestion-publique' => 'Audit - Contrôle de Gestion Publique',
    'gestion-marches-publics-partenariats' => 'Gestion des Marchés Publics et Partenariats',
    'gestion-administrative-rh-secteur-public' => 'Gestion Administrative et RH du Secteur Public',
    'finance-publique-collectivites-territoriales' => 'Finance Publique des Collectivités Territoriales',
];
