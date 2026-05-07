<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class AdminUserSeeder extends Seeder
{
    /**
     * Crée un compte super_admin local pour l'accès initial à Filament.
     * Email + mot de passe sont lus dans .env (FILAMENT_ADMIN_EMAIL / FILAMENT_ADMIN_PASSWORD).
     * AUCUNE valeur par défaut nominative — refuse de seed si l'env n'est pas configuré.
     */
    public function run(): void
    {
        $email = config('pssfp.admin_seeder.email');
        $password = config('pssfp.admin_seeder.password');

        if (empty($email) || empty($password)) {
            throw new RuntimeException(
                'FILAMENT_ADMIN_EMAIL et FILAMENT_ADMIN_PASSWORD doivent être définis dans .env avant de seed.'
            );
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Administrateur PSSFP',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        if (! $user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }
    }
}
