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
                'label' => 'Module 1 — Site institutionnel',
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
                'label' => 'Module 5 — Admissions',
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
                ],
            ],
            'bibliotheque' => [
                'label' => 'Module 3 — Bibliothèque',
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
                'sensitive' => [],
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
     * Toutes les permissions qui rattachent un rôle à un module : lecture,
     * gestion et actions sensibles (autonomes comprises). En porter une seule
     * suffit à ouvrir les écrans du module (cf. ModuleAccess) — les Policies
     * restent seules juges de ce que l'écran permet ensuite.
     *
     * @return list<string>
     */
    public static function modulePermissions(string $moduleKey): array
    {
        $module = self::modules()[$moduleKey] ?? null;

        if ($module === null) {
            return [];
        }

        return array_values(array_unique([
            ...$module['read'],
            ...$module['manage'],
            ...array_keys($module['sensitive']),
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
            $hasManage = $hasRead && self::containsAll($permissions, $module['manage']);

            $levels[$key] = match (true) {
                $hasManage => self::LEVEL_MANAGE,
                // Lecture partielle (rôle historique seedé à la main) : on
                // n'efface rien, on l'affiche comme lecture.
                $hasRead || array_intersect($permissions, $module['read']) !== [] => self::LEVEL_READ,
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
