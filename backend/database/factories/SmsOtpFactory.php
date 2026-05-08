<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SmsOtp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<SmsOtp>
 */
class SmsOtpFactory extends Factory
{
    protected $model = SmsOtp::class;

    public function definition(): array
    {
        $code = (string) $this->faker->numberBetween(100000, 999999);

        return [
            'phone_e164' => '+237'.$this->faker->numerify('6########'),
            'code_hash' => Hash::make($code),
            'purpose' => SmsOtp::PURPOSE_RESET_PIN,
            'attempts' => 0,
            'max_attempts' => 3,
            'expires_at' => now()->addMinutes(10),
            'used_at' => null,
            'cancelled_at' => null,
            'ip' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function used(): static
    {
        return $this->state(fn () => ['used_at' => now()]);
    }

    public function exhausted(): static
    {
        return $this->state(fn () => ['attempts' => 3]);
    }
}
