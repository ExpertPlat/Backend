<?php

namespace Database\Factories;

use App\Enums\ExpertKycApplicationStatus;
use App\Models\Expert;
use App\Models\ExpertKycApplication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExpertKycApplication>
 */
class ExpertKycApplicationFactory extends Factory
{
    protected $model = ExpertKycApplication::class;

    public function definition(): array
    {
        return [
            'reference' => 'KYC-'.now()->format('Y').'-'.Str::upper(Str::random(10)),
            'expert_id' => Expert::factory(),
            'attempt_number' => 1,
            'status' => ExpertKycApplicationStatus::Draft,
            'full_name' => fake()->name(),
            'email_snapshot' => fake()->safeEmail(),
            'country' => fake()->countryCode(),
            'language' => 'en',
            'domain' => 'legal',
            'jurisdiction' => fake()->country(),
            'payout_readiness' => 'not_ready',
        ];
    }
}
