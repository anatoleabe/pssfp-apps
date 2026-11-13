<?php

declare(strict_types=1);

/*
 * Modèles de messages proposés dans l'admin, au bouton « Notifier les
 * candidats sélectionnés ».
 *
 * Ce sont des propositions, pas des carcans : l'agent les charge puis les
 * modifie librement avant l'envoi. Les ajouter ici évite que chacun
 * réimprovise le ton institutionnel à chaque campagne.
 *
 * Variables disponibles, remplacées pour chaque destinataire :
 *   {prenom} {nom} {numero_dossier} {specialite} {date_cloture} {url}
 *
 * Contraintes SMS, à respecter pour les modèles destinés à ce canal :
 * - moins de 160 caractères une fois les variables remplacées, sinon
 *   l'opérateur facture deux messages ;
 * - alphabet GSM-7 : les accents é è à ù ç passent, l'apostrophe
 *   typographique ' et les guillemets « » font basculer en UCS-2, qui
 *   plafonne à 70 caractères. Utiliser l'apostrophe droite ;
 * - jamais de ponctuation collée à {url} : certaines applications SMS gardent
 *   le point dans le lien (« apply.pssfp.org. »), et la connexion échoue.
 */
return [
    'url' => env('RELANCE_SMS_URL', 'apply.pssfp.org'),

    'modeles' => [
        'dossier_incomplet' => [
            'libelle' => 'Dossier incomplet — rappel general',
            'sujet' => 'PSSFP — votre dossier de candidature est incomplet',
            'corps' => 'PSSFP : votre dossier {numero_dossier} n est pas encore complet. '
                .'Completez-le sur {url} avant le {date_cloture}.',
        ],
        'rappel_cloture' => [
            'libelle' => 'Rappel de la date de cloture',
            'sujet' => 'PSSFP — clôture des candidatures le {date_cloture}',
            'corps' => 'PSSFP : les candidatures ferment le {date_cloture}. '
                .'Verifiez votre dossier {numero_dossier} sur {url} avant cette date.',
        ],
        'frais_non_payes' => [
            'libelle' => 'Frais de dossier non regles',
            'sujet' => 'PSSFP — frais de dossier en attente',
            'corps' => 'PSSFP : les frais de dossier de la candidature {numero_dossier} '
                .'ne sont pas encore enregistres. Consultez {url} pour les details.',
        ],
        'depot_papier_attendu' => [
            'libelle' => 'Dossier papier non depose au guichet',
            'sujet' => 'PSSFP — dépôt du dossier papier',
            'corps' => 'PSSFP : votre dossier {numero_dossier} est soumis en ligne. '
                .'Deposez le dossier papier a la scolarite avant le {date_cloture}.',
        ],
        'convocation' => [
            'libelle' => 'Convocation / information generale',
            'sujet' => 'PSSFP — information importante',
            'corps' => 'PSSFP : information concernant votre candidature {numero_dossier}. '
                .'Connectez-vous sur {url} pour la consulter.',
        ],
    ],
];
