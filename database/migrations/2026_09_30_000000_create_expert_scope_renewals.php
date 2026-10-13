<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_scope_renewals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expert_id')->constrained('experts')->restrictOnDelete();
            $table->foreignId('scope_id')->constrained('expert_verified_scopes')->restrictOnDelete();
            $table->foreignId('open_scope_id')->nullable()->unique()->constrained('expert_verified_scopes')->restrictOnDelete();
            $table->foreignId('replacement_scope_id')->nullable()->constrained('expert_verified_scopes')->restrictOnDelete();
            $table->unsignedBigInteger('current_submission_id')->nullable();
            $table->unsignedBigInteger('reviewed_submission_id')->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('version')->default(1);
            $table->text('feedback')->nullable();
            $table->longText('history');
            $table->longText('review')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['expert_id', 'id']);
            $table->index(['status', 'id']);
            $table->index(['scope_id', 'id']);
        });
        Schema::create('expert_scope_renewal_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('renewal_id')->constrained('expert_scope_renewals')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->longText('evidence');
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64);
            $table->timestamps();
            $table->unique(['renewal_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_scope_renewal_submissions');
        Schema::dropIfExists('expert_scope_renewals');
    }
};
