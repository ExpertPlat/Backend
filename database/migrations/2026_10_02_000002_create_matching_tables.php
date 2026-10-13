<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matching_runs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $t->foreignId('scope_id')->constrained('case_service_scopes')->restrictOnDelete();
            $t->unsignedInteger('case_version');
            $t->foreignId('intake_version_id')->constrained('case_intake_versions')->restrictOnDelete();
            $t->foreignId('catalog_version_id')->constrained('catalog_versions')->restrictOnDelete();
            $t->unsignedBigInteger('catalog_revision');
            $t->string('algorithm_version', 32);
            $t->char('tie_break_seed', 32);
            $t->json('inputs');
            $t->unsignedInteger('eligible_count')->default(0);
            $t->unsignedInteger('excluded_count')->default(0);
            $t->timestamp('created_at');
            $t->index(['scope_id', 'id'], 'matching_runs_scope');
            $t->index(['case_id', 'case_version'], 'matching_runs_case_version');
        });
        Schema::create('matching_run_candidates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('run_id')->constrained('matching_runs')->restrictOnDelete();
            $t->foreignId('expert_id')->constrained('experts')->restrictOnDelete();
            $t->string('jurisdiction_code', 100);
            $t->boolean('eligible');
            $t->unsignedInteger('rank')->nullable();
            $t->char('tie_break_hash', 64)->nullable();
            $t->json('match_reasons');
            $t->json('exclusion_reasons');
            $t->timestamp('created_at');
            $t->unique(['run_id', 'expert_id', 'jurisdiction_code'], 'matching_candidate_unique');
            $t->index(['run_id', 'eligible', 'rank'], 'matching_candidate_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matching_run_candidates');
        Schema::dropIfExists('matching_runs');
    }
};
