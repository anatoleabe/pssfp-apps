<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * Lot 25 (décisions du 23/11/2026) : jury d'admission « admis seulement », et certificat de scolarité
 * délivré par la scolarité ou l'administration. Sans relancer RolePermissionSeeder en prod. Liste figée.
 */
return new class extends Migration
{
    private const DROIT = 'lmd.certificat_delivrer';

    private const ROLES = ['super_admin', 'admin', 'scolarite_responsable', 'scolarite_agent'];

    public function up(): void
    {
        Schema::table('deliberations', function (Blueprint $table): void {
            $table->boolean('admis_seulement')->default(false);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::DROIT, 'web');
        Role::query()->where('guard_name', 'web')->whereIn('name', self::ROLES)->get()->each(fn (Role $r) => $r->givePermissionTo(self::DROIT));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', self::DROIT)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::table('deliberations', function (Blueprint $table): void {
            $table->dropColumn('admis_seulement');
        });
    }
};
