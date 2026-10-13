<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_ai_question_sets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('intake_version_id')->constrained('case_intake_versions')->restrictOnDelete();
            $t->unsignedInteger('case_version');
            $t->string('purpose', 48);
            $t->string('provider', 24);
            $t->string('model', 100)->nullable();
            $t->string('status', 16)->default('pending');
            $t->string('failure_code', 48)->nullable();
            $t->char('request_key_hash', 64);
            $t->char('request_hash', 64);
            $t->json('input_summary');
            $t->string('provider_response_id', 100)->nullable();
            $t->unsignedInteger('latency_ms')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'case_id', 'request_key_hash'], 'case_ai_sets_request_key');
            $t->index(['case_id', 'intake_version_id', 'status'], 'case_ai_sets_case_input');
        });
        Schema::create('case_ai_questions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('set_id')->constrained('case_ai_question_sets')->restrictOnDelete();
            $t->string('key', 60);
            $t->text('question');
            $t->text('reason')->nullable();
            $t->string('priority', 8);
            $t->string('status', 16)->default('pending');
            $t->foreignId('answered_intake_version_id')->nullable()->constrained('case_intake_versions')->restrictOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['set_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_ai_questions');
        Schema::dropIfExists('case_ai_question_sets');
    }
};
