<?php

namespace Database\Factories;

use App\Models\CaseIntakeVersion;
use App\Models\CaseRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CaseRecordFactory extends Factory
{
    protected $model = CaseRecord::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'status' => 'draft', 'version' => 1, 'suitability' => 'not_assessed'];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (CaseRecord $case): void {
            $version = new CaseIntakeVersion;
            $version->forceFill(['case_id' => $case->id, 'version' => 1, 'schema_version' => 1, 'payload' => ['title' => 'Synthetic case', 'privacyChoice' => 'private', 'subjectType' => 'self', 'answers' => []], 'created_at' => now()])->save();
            $case->forceFill(['current_intake_version_id' => $version->id])->save();
        });
    }
}
