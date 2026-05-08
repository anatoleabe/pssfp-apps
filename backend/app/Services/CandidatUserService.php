<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Création et lookup des comptes User candidat.
 *
 * Idempotent : un même phone_e164 ne crée jamais plusieurs Users. Si le
 * compte existe déjà, le caller décide du comportement (409 sur register,
 * lookup transparent sur login).
 *
 * Le rôle Spatie `candidat` est assigné systématiquement à la création
 * (cf. arbitrage A du plan PR B — pas de colonne enum `role`).
 */
final class CandidatUserService
{
    public function existsForPhone(string $phoneE164): bool
    {
        return User::where('phone_e164', $phoneE164)->exists();
    }

    public function findByPhone(string $phoneE164): ?User
    {
        return User::where('phone_e164', $phoneE164)->first();
    }

    /**
     * Crée un User candidat. À utiliser uniquement après s'être assuré
     * qu'aucun User n'existe pour ce phone (sinon 409 côté controller).
     *
     * @param  array{prenom: string, nom: string, phone_country: string}  $profile
     */
    public function createCandidat(string $phoneE164, string $pin, array $profile): User
    {
        $user = User::create([
            'name' => trim("{$profile['prenom']} {$profile['nom']}"),
            'email' => null,
            'password' => Hash::make($pin),
            'phone_e164' => $phoneE164,
            'phone_country' => $profile['phone_country'],
        ]);

        $user->assignRole('candidat');

        return $user;
    }

    /**
     * Met à jour le PIN d'un candidat existant. Hash via password Argon2/bcrypt
     * (selon config `hashing.driver`). Le caller est responsable de révoquer
     * les tokens Sanctum après l'appel (cf. ajout 3 plan PR B).
     */
    public function updatePin(User $user, string $newPin): void
    {
        $user->forceFill([
            'password' => Hash::make($newPin),
        ])->save();
    }
}
