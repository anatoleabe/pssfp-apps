<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Carte « module → niveau d'accès → permissions Spatie ».
 *
 * Source unique de vérité pour l'écran de gestion des rôles (RoleResource) et
 * pour le RolePermissionSeeder. L'administrateur ne manipule jamais de
 * permissions à l'unité : il choisit, module par module, un niveau
 * (aucun / lecture / gestion), plus les actions sensibles du module, à
 * cocher une à une (décisions d'admission, publication d'un document…).
 *
 * Les permissions restent des permissions Spatie classiques : tout le code
 * existant (`$user->can('candidature.accept')`, policies, Resources Filament)
 * continue de fonctionner sans changement.
 */
final class ModulePermissionMap
{
    public const LEVEL_NONE = 'none';

    public const LEVEL_READ = 'read';

    public const LEVEL_MANAGE = 'manage';

    /** Rôles dont les droits ne se pilotent pas depuis l'écran des rôles. */
    public const LOCKED_ROLES = ['super_admin', 'candidat'];

    /**
     * Libellés des niveaux, pour les Select de l'interface.
     *
     * @return array<string, string>
     */
    public static function levels(): array
    {
        return [
            self::LEVEL_NONE => 'Aucun accès',
            self::LEVEL_READ => 'Lecture seule',
            self::LEVEL_MANAGE => 'Gestion complète',
        ];
    }

    /**
     * Définition des modules.
     *
     * `read` liste les permissions du niveau lecture ; `manage` liste celles
     * qui s'y **ajoutent** au niveau gestion ; `sensitive` les actions
     * sensibles du module (permission => libellé), volontairement hors des
     * niveaux : « gestion » n'emporte jamais le droit de décider ou de publier.
     *
     * `standalone` (facultatif) désigne, parmi les actions sensibles, celles
     * qui ont un sens **sans** niveau d'accès au module (ex. un contributeur
     * qui dépose ses documents sans voir le catalogue) : l'écran des rôles les
     * propose même au niveau « aucun ».
     *
     * Chaque clé est aussi le nom de module que déclarent les écrans du
     * panneau (#[BelongsToModule('bibliotheque')]) : ajouter un module, c'est
     * ajouter une entrée ici (cf. docs/ajouter-un-module.md).
     *
     * @return array<string, array{label: string, description: string, read: list<string>, manage: list<string>, sensitive: array<string, string>, standalone?: list<string>, sensitive_description?: string}>
     */
    public static function modules(): array
    {
        return [
            'site' => [
                'label' => 'Site institutionnel',
                'description' => 'Pages, actualités et médiathèque de pssfp.org.',
                'read' => [
                    'view_any_page', 'view_page',
                    'view_any_article', 'view_article',
                    'view_any_asset', 'view_asset',
                ],
                'manage' => [
                    'create_page', 'update_page', 'delete_page',
                    'create_article', 'update_article', 'delete_article',
                    'create_asset', 'update_asset', 'delete_asset',
                ],
                'sensitive' => [],
            ],
            'admissions' => [
                'label' => 'Admissions',
                'description' => 'Dossiers de candidature et campagnes. Les campagnes restent en lecture seule hors administrateurs.',
                'read' => [
                    'view_any_candidature', 'view_candidature',
                    'view_any_campagne::candidature', 'view_campagne::candidature',
                ],
                'manage' => [
                    'update_candidature',
                    'create_campagne::candidature', 'update_campagne::candidature',
                ],
                'sensitive_description' => 'À cocher explicitement : gérer les dossiers n\'emporte pas le droit de décider d\'une admission.',
                'sensitive' => [
                    'candidature.mark_depot_physique' => 'Pointer la réception des dossiers papier au guichet',
                    'candidature.export_csv' => 'Exporter les candidatures en CSV',
                    'candidature.mark_paid' => 'Marquer les frais de dossier comme payés',
                    'candidature.update_status' => 'Basculer un dossier entre postulant et candidat',
                    'candidature.accept' => 'Accepter un dossier',
                    'candidature.refuse' => 'Refuser un dossier',
                    'candidature.bulk_decision' => 'Accepter ou refuser en lot',
                    'candidature.regenerate_recipisse' => 'Régénérer le récépissé PDF d\'un dossier',
                    'candidature.withdraw' => 'Retirer administrativement un dossier',
                    'candidature.delete_test' => 'Supprimer définitivement un compte de test',
                    'candidature.notify' => 'Notifier un groupe de candidats par SMS ou e-mail',
                    'campagne.download_resultats' => 'Télécharger le communiqué des résultats et le PV du jury (listes nominatives)',
                    'campagne.export_retenus' => 'Télécharger les exports des retenus (données complètes, ACMA)',
                    'candidature.matricules_moodle' => 'Attribuer les matricules, créer les comptes sur eCampus, exporter les comptes Moodle, voir et envoyer le mot de passe Moodle d\'un auditeur',
                    'candidature.ecampus_mot_de_passe' => 'Vérifier le compte eCampus d\'un auditeur, en réinitialiser le mot de passe à sa demande et ouvrir sa fiche sur eCampus',
                    'candidature.ajouter_retenu' => 'Ajouter un retenu depuis l\'admin, sans dossier déposé sur le portail de candidature, et corriger son téléphone',
                    'candidature.lettres_signees' => 'Déposer, remplacer, consulter et retirer les lettres d\'admission signées des auditeurs',
                    'candidature.espace_candidat' => 'Donner un nouveau code de connexion à un candidat et voir son espace (lecture seule)',
                ],
            ],
            'scolarite' => [
                'label' => 'Scolarité',
                'description' => 'Auditeurs, versements et situation financière. La gestion donne le droit d\'enregistrer un versement : elle revient au service financier seul (décision du 15/11/2026) ; la scolarité et l\'administrateur sont en lecture avec leurs actions sensibles.',
                'read' => [
                    'view_any_auditeur', 'view_auditeur',
                ],
                'manage' => [
                    'scolarite.versement_saisir',
                ],
                'sensitive_description' => 'À cocher explicitement : enregistrer un versement n\'emporte pas le droit de l\'annuler.',
                'sensitive' => [
                    'scolarite.export' => 'Exporter la situation financière des auditeurs',
                    'finances.consulter' => 'Consulter l\'espace Finances : listes des versements et des reçus (service financier, responsable de scolarité, administrateurs)',
                    'finances.rubriques_gerer' => 'Modifier les rubriques de frais des reçus (libellé, code d\'imputation, montant, ordre) et le compte de scolarité de chaque promotion (administrateurs)',
                    'scolarite.versement_annuler' => 'Annuler un versement ; décider les demandes de correction du service financier',
                    'scolarite.versement_corriger' => 'Demander la correction ou l\'annulation d\'un versement (service financier) : décidée par un titulaire de l\'annulation, jamais par le demandeur',
                    'scolarite.versement_confirmer' => 'Confirmer un versement sur présentation du reçu bancaire original (scolarité) : scan du bordereau et attestation, jamais un versement que l\'on a saisi',
                    'scolarite.recu_emettre' => 'Émettre les reçus des versements et passer leurs écritures d\'annulation (service financier)',
                    'scolarite.remise' => 'Accorder une remise',
                    'scolarite.bareme' => 'Modifier les barèmes',
                    'scolarite.promotion' => 'Modifier les réglages d\'une promotion (lettres, rentrée, consignes)',
                    'scolarite.promotion_anterieure' => 'Créer une promotion antérieure (rattachement d\'un ancien auditeur réinscrit depuis l\'archive ACMA)',
                    'scolarite.derogation_m2' => 'Accorder, à la réinscription d\'un ancien, une dérogation au M1 admis pour l\'inscrire en M2 (avec sa pièce)',
                    'scolarite.inscrire' => 'Réinscrire un auditeur dans l\'année en cours depuis sa fiche et lancer le passage en groupe du M1 au M2 (administration et scolarité)',
                    'scolarite.inscrire_ancien' => 'Réinscrire un ancien auditeur depuis son dossier d\'archive ACMA dans l\'année en cours (matricule ACMA conservé), dérogation M2 comprise',
                    'scolarite.statut' => 'Changer le statut d\'un auditeur (abandon, exclu, diplômé, retour à actif)',
                    'scolarite.suspension' => 'Suspendre un auditeur pour l\'année en cours sur sa demande écrite (jointe), et lever la suspension : compte eCampus suspendu, reste de l\'année non réclamé (administrateurs)',
                    'scolarite.messages' => 'Envoyer un message aux auditeurs (relance des impayés)',
                    'scolarite.lettre_acces' => 'Télécharger depuis sa fiche la lettre d\'accès FOAD (avec le mot de passe) d\'un auditeur dont l\'inscription est soldée ; lui renvoyer le SMS et l\'e-mail des accès, ou un nouveau lien vers sa lettre',
                    'scolarite.deblocage' => 'Débloquer l\'accès à eCampus avant paiement sur un virement annoncé, et le prolonger une fois de 30 jours au plus ; enregistrer les engagements de prise en charge des organismes, y rattacher des auditeurs et relancer l\'organisme',
                    'scolarite.deblocage_exceptionnel' => 'Accorder un moratoire ou une dérogation (accès avant paiement), prolonger et révoquer tout déblocage, prolonger un engagement de prise en charge',
                    'scolarite.etiquettes' => 'Poser et retirer des étiquettes sur les auditeurs ; créer, renommer et supprimer les étiquettes',
                ],
            ],
            'lmd' => [
                'label' => 'Parcours LMD',
                'description' => 'Menus « Référentiel pédagogique », « Planification », « Évaluations et jurys » et « Enseignants et paie ».',
                'read' => [
                    'lmd.consulter',
                ],
                'manage' => [
                    'lmd.referentiel',
                    'lmd.inscrire',
                    'lmd.notes_saisir',
                    'lmd.deliberation_preparer',
                    'lmd.requetes_traiter',
                    'lmd.assiduite',
                    'lmd.memoires_suivre',
                    'lmd.calendrier_preparer',
                    'lmd.actes_produire',
                    'lmd.emplois_du_temps',
                    'lmd.programmation',
                    'lmd.pointage_enseignants',
                    'lmd.paie_preparer',
                ],
                'sensitive_description' => 'À cocher explicitement : préparer une maquette n\'emporte pas le droit de la valider, ni saisir des notes celui de verrouiller une étape.',
                'sensitive' => [
                    'lmd.maquette_valider' => 'Valider ou rouvrir une maquette',
                    'lmd.regles' => 'Modifier les règles d\'évaluation de l\'année',
                    'lmd.parcours_changer' => 'Changer le parcours (option) d\'un auditeur',
                    'lmd.notes_verrouiller' => 'Ouvrir et verrouiller les étapes de saisie des notes',
                    'lmd.notes_rouvrir' => 'Rouvrir une étape de saisie verrouillée (avec motif)',
                    'lmd.derogation_rattrapage' => 'Accorder une dérogation pour le rattrapage',
                    'lmd.notes_enseignant' => 'Saisir le contrôle continu et l\'assiduité de ses ECUE (enseignant)',
                    'lmd.point_focal' => 'Tenir l\'assiduité et le contrôle continu des cours de ses classes, voir la liste de ses auditeurs (point focal)',
                    'lmd.deliberation_arreter' => 'Arrêter et rectifier une délibération (résultats figés, PV numéroté)',
                    'lmd.deliberation_publier' => 'Publier les résultats d\'une délibération',
                    'lmd.jury_signer' => 'Consulter les délibérations de ses jurys et signer leur PV (membre de jury)',
                    'lmd.documents_revoquer' => 'Révoquer un document officiel délivré (relevé, attestation, certificat)',
                    'lmd.certificat_delivrer' => 'Délivrer le certificat de scolarité d\'un auditeur depuis sa fiche (il ne le produit pas lui-même)',
                    'lmd.documents_lever' => 'Lever la restriction « en règle » sur les documents d\'un auditeur',
                    'lmd.requetes_coter' => 'Coter les requêtes, les déclarer irrecevables',
                    'lmd.requetes_jury' => 'Tenir les séances du jury des requêtes (mentions, clôture du PV)',
                    'lmd.requetes_valider' => 'Valider définitivement les PV de requêtes (chef de la commission)',
                    'lmd.anonymat_gerer' => 'Gérer les anonymats (numéros, constats, levée avec la clé) — sans saisir de notes',
                    'lmd.anonymat_saisir' => 'Saisir les notes d\'examen sur les numéros d\'anonymat (opérateur)',
                    'lmd.memoires_attester' => 'Délivrer l\'attestation d\'autorisation de dépôt du mémoire, autoriser un retard d\'étape (chef de la scolarité)',
                    'lmd.calendrier_reajuster' => 'Réajuster un calendrier signé (dates, avec motif) et le publier',
                    'lmd.calendrier_signer' => 'Signer le calendrier académique ou le renvoyer en préparation (chef de la structure)',
                    'lmd.actes_signer' => 'Enregistrer la signature d\'un acte (numéro, date, pièce signée), le renvoyer ou le diffuser',
                    'lmd.programmation_valider' => 'Valider les quotas horaires et les programmations des enseignants, les notifier, fixer le plafond de charge',
                    'lmd.programmation_repondre' => 'Voir ses programmations, les accepter ou les décliner, déclarer ses indisponibilités (enseignant)',
                    'lmd.pointage_emarger' => 'Pointer ses séances (arrivée, départ, contenu enseigné) et suivre ses émargements (enseignant)',
                    'lmd.dossier_paie' => 'Voir et saisir le NIU, la banque et le compte de virement des enseignants (service financier)',
                    'lmd.paie_verifier' => 'Valider le barème des enseignants, vérifier, transmettre et rejeter les états de paie',
                    'lmd.paie_viser' => 'Viser les états de paie (contrôleur interne)',
                    'lmd.paie_payer' => 'Prendre en charge et payer les états de paie des enseignants (service financier)',
                    'lmd.controle_gestion' => 'Consulter le contrôle de gestion : coûts par UE et par parcours, tableau de bord mensuel, dossiers en retard',
                ],
                'standalone' => ['lmd.notes_enseignant', 'lmd.point_focal', 'lmd.jury_signer', 'lmd.requetes_valider', 'lmd.anonymat_gerer', 'lmd.anonymat_saisir', 'lmd.calendrier_signer', 'lmd.actes_signer', 'lmd.programmation_repondre', 'lmd.pointage_emarger', 'lmd.dossier_paie', 'lmd.paie_viser', 'lmd.paie_payer', 'lmd.controle_gestion'],
            ],
            'bibliotheque' => [
                'label' => 'Bibliothèque',
                'description' => 'Documents de la bibliothèque virtuelle et thésaurus. La publication reste une action sensible.',
                'read' => [
                    'view_any_document', 'view_document',
                    'view_any_mot::cle', 'view_mot::cle',
                ],
                'manage' => [
                    'create_document', 'update_document', 'delete_document',
                    'create_mot::cle', 'update_mot::cle', 'delete_mot::cle',
                ],
                'sensitive_description' => 'À cocher explicitement : gérer les documents n\'emporte pas le droit de les mettre en ligne.',
                'sensitive' => [
                    'document.publish' => 'Publier ou dépublier un document de la bibliothèque',
                    'library.thesaurus' => 'Organiser le thésaurus (thèmes)',
                    'library.redirects' => 'Gérer les anciennes adresses',
                    'library.stats_export' => 'Exporter les statistiques',
                    'document.contribute' => 'Déposer des documents (contributeur)',
                ],
                'standalone' => ['document.contribute'],
            ],
            'administration' => [
                'label' => 'Administration',
                'description' => 'Comptes back-office, rôles et réglages applicatifs.',
                'read' => [
                    'view_any_user', 'view_user',
                    'view_any_role', 'view_role',
                ],
                'manage' => [
                    'create_user', 'update_user', 'delete_user',
                    'create_role', 'update_role', 'delete_role',
                    'settings.manage',
                ],
                'sensitive' => [
                    'journal.view' => 'Consulter le journal d\'activité de toute l\'application (qui a fait quoi, quand)',
                    'supervision.consulter' => 'Consulter le contrôle interne : anomalies détectées par les contrôles automatiques',
                    'supervision.traiter' => 'Traiter les anomalies du contrôle interne : prise en charge, justification, exécution des contrôles',
                ],
                // Le contrôle interne s'ouvre sans autre droit d'administration (responsable de scolarité).
                'standalone' => ['supervision.consulter', 'supervision.traiter'],
            ],
            // Lot 15d (spec du lot 15, §7.10 ; ADR-0014) : archive d'ACMA, consultée par l'administration seule.
            'archives' => [
                'label' => 'Archives ACMA',
                'description' => 'Menu « Scolarité > Archives ACMA » : dossiers des anciens auditeurs repris d\'ACMA (identité, années, notes, paiements, soutenances), en lecture seule.',
                'read' => [
                    'archives.consulter',
                ],
                'manage' => [],
                'sensitive_description' => 'L\'archive ne se modifie jamais : on la complète d\'après une pièce (dossier papier, PV signé), et on arbitre son lien avec un auditeur de la plateforme.',
                'sensitive' => [
                    'archives.completer' => 'Compléter un dossier d\'archive : date de naissance d\'après le dossier papier, décision transcrite d\'un PV signé, note retenue en cas de conflit',
                    'archives.rapprocher' => 'Arbitrer le rapprochement d\'un dossier d\'archive avec un auditeur de la plateforme (lier, ne pas lier, écarter) et télécharger la liste des cas à arbitrer',
                ],
            ],
        ];
    }

    /**
     * Actions sensibles de tous les modules, à plat.
     *
     * @return array<string, string> permission => libellé
     */
    public static function sensitiveActions(): array
    {
        return array_merge(...array_values(array_map(
            fn (array $module): array => $module['sensitive'],
            self::modules(),
        )));
    }

    /**
     * Actions sensibles « autonomes » de tous les modules : valables même sans
     * niveau d'accès au module.
     *
     * @return list<string>
     */
    public static function standaloneActions(): array
    {
        return array_values(array_merge(...array_values(array_map(
            fn (array $module): array => $module['standalone'] ?? [],
            self::modules(),
        ))));
    }

    /**
     * Permissions qui **ouvrent** un module : lecture, gestion et actions
     * autonomes. En porter une seule suffit à ouvrir les écrans du module
     * (cf. ModuleAccess) — les Policies restent seules juges de ce que
     * l'écran permet ensuite.
     *
     * Les actions sensibles ordinaires n'en font pas partie : sans niveau
     * d'accès elles sont inopérantes (cf. toPermissions()), et une telle
     * permission posée à la main ne doit pas ouvrir le module.
     *
     * @return list<string>
     */
    public static function accessPermissions(string $moduleKey): array
    {
        $module = self::modules()[$moduleKey] ?? null;

        if ($module === null) {
            return [];
        }

        return array_values(array_unique([
            ...$module['read'],
            ...$module['manage'],
            ...($module['standalone'] ?? []),
        ]));
    }

    /**
     * Toutes les permissions connues du projet (modules + actions sensibles).
     *
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        $permissions = [];

        foreach (self::modules() as $module) {
            $permissions = [...$permissions, ...$module['read'], ...$module['manage']];
        }

        return array_values(array_unique([
            ...$permissions,
            ...array_keys(self::sensitiveActions()),
        ]));
    }

    /**
     * Traduit un choix de l'interface en liste de permissions Spatie.
     *
     * @param  array<string, string>  $levels  module => niveau
     * @param  list<string>  $sensitiveActions  permissions sensibles cochées
     * @return list<string>
     */
    public static function toPermissions(array $levels, array $sensitiveActions = []): array
    {
        $permissions = [];

        foreach (self::modules() as $moduleKey => $module) {
            $level = $levels[$moduleKey] ?? self::LEVEL_NONE;
            $opened = $level === self::LEVEL_READ || $level === self::LEVEL_MANAGE;

            if ($opened) {
                $permissions = [...$permissions, ...$module['read']];
            }

            if ($level === self::LEVEL_MANAGE) {
                $permissions = [...$permissions, ...$module['manage']];
            }

            // Actions sensibles connues seulement. Sans accès au module, une
            // action sensible serait inopérante : on ne garde alors que les
            // actions autonomes (dépôt contributeur…).
            $allowed = $opened ? array_keys($module['sensitive']) : ($module['standalone'] ?? []);
            $permissions = [...$permissions, ...array_values(array_intersect($sensitiveActions, $allowed))];
        }

        return array_values(array_unique($permissions));
    }

    /**
     * Opération inverse : déduit les niveaux par module d'un jeu de permissions.
     *
     * Utilisé pour pré-remplir le formulaire d'édition d'un rôle existant.
     *
     * @param  list<string>  $permissions
     * @return array<string, string> module => niveau
     */
    public static function toLevels(array $permissions): array
    {
        $levels = [];

        foreach (self::modules() as $key => $module) {
            $hasRead = self::containsAll($permissions, $module['read']);
            // Un module sans permission de gestion (archives ACMA, en lecture seule) ne s'affiche jamais « Gestion ».
            $hasManage = $hasRead && $module['manage'] !== [] && self::containsAll($permissions, $module['manage']);

            // Toute permission de lecture ou de gestion ouvre le module
            // (ModuleAccess) : l'écran ne doit jamais l'afficher « Aucun
            // accès », sinon un simple enregistrement le refermerait.
            $opens = array_intersect($permissions, [...$module['read'], ...$module['manage']]) !== [];

            $levels[$key] = match (true) {
                $hasManage => self::LEVEL_MANAGE,
                // Accès partiel (rôle historique seedé à la main, permission
                // de gestion sans lecture) : on n'efface rien, on l'affiche
                // comme lecture.
                $opens => self::LEVEL_READ,
                default => self::LEVEL_NONE,
            };
        }

        return $levels;
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needles
     */
    private static function containsAll(array $haystack, array $needles): bool
    {
        return $needles !== [] && array_diff($needles, $haystack) === [];
    }
}
