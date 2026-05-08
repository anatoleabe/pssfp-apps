<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Crée un User candidat (pas d'email, phone E.164, PIN par défaut 472816).
     * À combiner avec ->afterCreating(fn ($u) => $u->assignRole('candidat')) si besoin
     * via la closure factory(), ou utiliser le helper candidatWithRole() du test.
     */
    public function candidat(?string $pin = null): static
    {
        return $this->state(fn () => [
            'name' => fake()->firstName().' '.fake()->lastName(),
            'email' => null,
            'email_verified_at' => null,
            'password' => Hash::make($pin ?? '472816'),
            'phone_e164' => '+237'.fake()->numerify('6########'),
            'phone_country' => 'CM',
        ])->afterCreating(function (User $user): void {
            $user->assignRole('candidat');
        });
    }
}
