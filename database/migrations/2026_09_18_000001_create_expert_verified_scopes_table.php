<?php

use App\Enums\ExpertScopeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_verified_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kyc_application_id')
                ->constrained('expert_kyc_applications')
                ->restrictOnDelete();
            $table->foreignId('verified_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('domain', 50);
            $table->string('jurisdiction');
            $table->string('role', 100);
            $table->json('service_types');
            $table->json('languages');
            $table->string('status', 32)->default(ExpertScopeStatus::Active->value);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->index(['expert_id', 'status']);
            $table->index(['status', 'valid_until']);
            $table->index(['domain', 'jurisdiction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_verified_scopes');
    }
};
