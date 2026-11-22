<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * Points focaux de classe (spec module 4, lot 16b) : affectation d'un compte à une classe
 * (un parcours pour une année), rôle `point_focal` et son droit `lmd.point_focal`.
 *
 * Sans relancer RolePermissionSeeder en prod. Strictement additif. Le retour arrière retire le rôle
 * aux comptes qui le portent et supprime les affectations.
 */
return new class extends Migration
{
    private const PERMISSION = 'lmd.point_focal';

    private const ROLE = [
        'label' => 'Point focal de classe',
        'description' => 'Assiduité et contrôle continu des cours de sa classe ; liste de ses auditeurs en lecture seule.',
    ];

    public function up(): void
    {
        Schema::create('affectations_points_focaux', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parcours_id')->constrained('parcours')->restrictOnDelete();
            $table->string('annee_academique', 9);
            $table->timestampsTz();

            $table->unique(['user_id', 'parcours_id', 'annee_academique'], 'affectations_points_focaux_unique');
            $table->index(['parcours_id', 'annee_academique']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::PERMISSION, 'web');
        $role = Role::findOrCreate('point_focal', 'web');
        if ($role->wasRecentlyCreated) {
            $role->forceFill(self::ROLE)->save();
        }
        $role->givePermissionTo(self::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('guard_name', 'web')->where('name', 'point_focal')->where('description', self::ROLE['description'])->delete();
        Permission::query()->where('guard_name', 'web')->where('name', self::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::dropIfExists('affectations_points_focaux');
    }
};
