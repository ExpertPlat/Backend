<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expert_kyc_applications', function (Blueprint $table): void {
            $table->foreignId('source_application_id')
                ->nullable()
                ->after('attempt_number')
                ->constrained('expert_kyc_applications')
                ->nullOnDelete();
            $table->json('requested_changes')->nullable()->after('decision_reason');
        });
    }

    public function down(): void
    {
        Schema::table('expert_kyc_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_application_id');
            $table->dropColumn('requested_changes');
        });
    }
};
