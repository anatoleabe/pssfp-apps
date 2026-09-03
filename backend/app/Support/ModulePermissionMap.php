<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Carte « module → niveau d'accès → permissions Spatie ».
 *
 * Source unique de vérité pour l'écran de gestion des rôles (RoleResource) et
 * pour le RolePermissionSeeder. L'administrateur ne manipule jamais de
 * permissions à l'unité : il choisit, module par module, un niveau
 * (aucun / lecture / gestion), plus quelques actions sensibles à cocher
 * explicitement pour le module Admissions.
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
     * qui s'y **ajoutent** au niveau gestion.
     *
     * @return array<string, array{label: string, description: string, read: list<string>, manage: list<string>}>
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
            ],
        ];
    }

    /**
     * Actions sensibles du module Admissions, cochées une à une.
     *
     * Volontairement hors des niveaux : « gestion des admissions » ne doit pas
     * emporter automatiquement le droit de décider d'une admission.
     *
     * @return array<string, string> permission => libellé
     */
    public static function sensitiveActions(): array
    {
        return [
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
        ];
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
        $modules = self::modules();

        foreach ($levels as $moduleKey => $level) {
            $module = $modules[$moduleKey] ?? null;

            if ($module === null || $level === self::LEVEL_NONE) {
                continue;
            }

            $permissions = [...$permissions, ...$module['read']];

            if ($level === self::LEVEL_MANAGE) {
                $permissions = [...$permissions, ...$module['manage']];
            }
        }

        // Une action sensible sans accès en lecture aux admissions serait
        // inopérante (l'agent ne verrait aucun dossier) : on ne retient que
        // celles qui existent réellement, la cohérence est assurée en amont
        // par l'écran des rôles.
        $known = array_keys(self::sensitiveActions());
        $permissions = [...$permissions, ...array_values(array_intersect($sensitiveActions, $known))];

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
