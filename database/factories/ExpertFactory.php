<?php

namespace Database\Factories;

use App\Enums\ExpertKycStatus;
use App\Models\Expert;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Expert>
 */
class ExpertFactory extends Factory
{
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
            'email_verified_at' => null,
            'country' => fake()->countryCode(),
            'language' => 'en',
            'domain' => fake()->randomElement(['legal', 'finance', 'technology', 'business']),
            'password' => Hash::make(Str::password(16)),
            'is_active' => true,
            'kyc_status' => ExpertKycStatus::NotSubmitted,
        ];
    }

    /**
     * Mark the expert's email address as verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Mark the expert account as unavailable.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
