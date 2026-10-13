<?php

use App\Enums\ExpertKycApplicationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_kyc_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('expert_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 32)->default(ExpertKycApplicationStatus::Draft->value);
            $table->string('full_name');
            $table->string('email_snapshot');
            $table->string('country', 16)->nullable();
            $table->string('language', 16)->nullable();
            $table->string('domain', 50)->nullable();
            $table->string('jurisdiction')->nullable();
            $table->string('payout_readiness', 32)->default('not_ready');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->unique(['expert_id', 'attempt_number']);
            $table->index(['expert_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_kyc_applications');
    }
};
