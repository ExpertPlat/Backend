<?php

namespace Database\Factories;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
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
            'password' => Hash::make(Str::password(16)),
            'is_active' => true,
            'invitation_accepted_at' => now(),
            'last_login_at' => null,
        ];
    }

    /**
     * Mark the administrator as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function pendingInvitation(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'invitation_accepted_at' => null,
        ]);
    }
}
