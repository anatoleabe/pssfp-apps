<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles de la bibliothèque (Plan 6) et caisse séparée, sans relancer
 * RolePermissionSeeder en prod (le seeder resynchronise et écraserait les
 * réglages faits depuis l'écran Rôles).
 *
 * - 4 nouvelles permissions du module bibliothèque, accordées à `super_admin`
 *   (pas de contournement de Gate) et `admin` ;
 * - rôles `library_manager`, `library_contributor`, `library_viewer` et
 *   `caisse` ;
 * - l'encaissement des frais de dossier quitte `librarian` : tout titulaire
 *   de `librarian` reçoit d'abord `caisse`, puis `librarian` perd
 *   `candidature.mark_paid` et la lecture des admissions. Personne ne perd un
 *   droit qu'il exerce.
 *
 * Listes figées à la date de la migration : une évolution ultérieure de
 * ModulePermissionMap ne doit pas changer ce que cette migration a accordé.
 * Le seeder produit le même état sur une installation neuve
 * (cf. LibraryAdminRolesMigrationTest).
 *
 * Un rôle de même nom déjà présent (créé à la main depuis l'écran Rôles) est
 * complété sans être renommé ; `down()` ne supprime que les rôles portant
 * encore la description posée ici (marqueur de création par la migration).
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'library.thesaurus',
        'library.redirects',
        'library.stats_export',
        'document.contribute',
    ];

    /** Rôles qui reçoivent les nouvelles permissions. */
    private const GRANTED_ROLES = ['super_admin', 'admin'];

    private const BIBLIO_READ = [
        'view_any_document', 'view_document',
        'view_any_mot::cle', 'view_mot::cle',
    ];

    private const BIBLIO_MANAGE = [
        'create_document', 'update_document', 'delete_document',
        'create_mot::cle', 'update_mot::cle', 'delete_mot::cle',
    ];

    /** Ce que `librarian` cède à `caisse`. */
    private const LIBRARIAN_TO_CAISSE = [
        'view_any_candidature', 'view_candidature',
        'view_any_campagne::candidature', 'view_campagne::candidature',
        'candidature.mark_paid',
    ];

    private const LIBRARIAN_OLD_DESCRIPTION = 'Bibliothèque virtuelle et encaissement des frais de dossier.';

    private const LIBRARIAN_NEW_DESCRIPTION = 'Bibliothèque seulement : gestion des documents et du thésaurus, publication.';

    /**
     * @return array<string, array{label: string, description: string, permissions: list<string>}>
     */
    private function roles(): array
    {
        return [
            'library_manager' => [
                'label' => 'Responsable de la bibliothèque',
                'description' => 'Bibliothèque seulement : documents, thésaurus, publication, anciennes adresses et statistiques.',
                'permissions' => [
                    ...self::BIBLIO_READ, ...self::BIBLIO_MANAGE,
                    'document.publish', 'library.thesaurus', 'library.redirects', 'library.stats_export',
                ],
            ],
            'library_contributor' => [
                'label' => 'Contributeur bibliothèque',
                'description' => 'Bibliothèque seulement : dépôt de ses propres documents, soumis à validation.',
                'permissions' => ['document.contribute'],
            ],
            'library_viewer' => [
                'label' => 'Consultation bibliothèque',
                'description' => 'Bibliothèque seulement : consultation du catalogue et export des statistiques.',
                'permissions' => [...self::BIBLIO_READ, 'library.stats_export'],
            ],
            'caisse' => [
                'label' => 'Caisse — frais de dossier',
                'description' => 'Admissions en lecture et encaissement des frais de dossier.',
                'permissions' => self::LIBRARIAN_TO_CAISSE,
            ],
        ];
    }

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $newPermissions = collect(self::NEW_PERMISSIONS)->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        Role::query()->where('guard_name', 'web')->whereIn('name', self::GRANTED_ROLES)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($newPermissions));

        foreach ($this->roles() as $name => $definition) {
            $role = Role::query()->where('guard_name', 'web')->where('name', $name)->first();

            if ($role === null) {
                $role = new Role(['name' => $name, 'guard_name' => 'web']);
                $role->forceFill(['label' => $definition['label'], 'description' => $definition['description']])->save();
            }

            $role->givePermissionTo(collect($definition['permissions'])->map(fn (string $p) => Permission::findOrCreate($p, 'web')));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $librarian = Role::query()->where('guard_name', 'web')->where('name', 'librarian')->first();

        if ($librarian !== null) {
            // D'abord la caisse aux titulaires, ensuite seulement le retrait.
            $librarian->users()->get()->each(fn ($user) => $user->assignRole('caisse'));

            $librarian->revokePermissionTo(
                Permission::query()->where('guard_name', 'web')->whereIn('name', self::LIBRARIAN_TO_CAISSE)->get()
            );

            if ($librarian->description === self::LIBRARIAN_OLD_DESCRIPTION) {
                $librarian->forceFill(['description' => self::LIBRARIAN_NEW_DESCRIPTION])->save();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Rend `candidature.mark_paid` et la lecture des admissions à `librarian`
     * (et son ancienne description si elle n'a pas été retouchée), supprime
     * les 4 permissions, et supprime chacun des 4 rôles **seulement** s'il
     * porte encore la description posée par up().
     *
     * Cas d'un rôle `caisse` qui existait avant la migration (description
     * différente) : il est conservé avec ses titulaires, y compris les
     * bibliothécaires à qui up() l'a donné, et garde les permissions qu'up()
     * lui a ajoutées (lecture admissions, `candidature.mark_paid`) : on ne
     * sait pas distinguer celles qu'il portait déjà. Aucun droit n'est donc
     * perdu ; à nettoyer à la main depuis l'écran Rôles si besoin.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $librarian = Role::query()->where('guard_name', 'web')->where('name', 'librarian')->first();

        if ($librarian !== null) {
            $librarian->givePermissionTo(collect(self::LIBRARIAN_TO_CAISSE)->map(fn (string $p) => Permission::findOrCreate($p, 'web')));

            if ($librarian->description === self::LIBRARIAN_NEW_DESCRIPTION) {
                $librarian->forceFill(['description' => self::LIBRARIAN_OLD_DESCRIPTION])->save();
            }
        }

        // Seuls les rôles encore porteurs de la description posée par up()
        // sont supprimés : un rôle préexistant, ou retouché depuis, reste.
        foreach ($this->roles() as $name => $definition) {
            Role::query()->where('guard_name', 'web')->where('name', $name)
                ->where('description', $definition['description'])
                ->get()
                ->each(fn (Role $role) => $role->delete());
        }

        Permission::query()->where('guard_name', 'web')
            ->whereIn('name', self::NEW_PERMISSIONS)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
