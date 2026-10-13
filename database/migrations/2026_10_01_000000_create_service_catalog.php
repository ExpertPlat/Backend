<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_locks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('revision')->default(1);
        });
        DB::table('catalog_locks')->insert(['id' => 1, 'revision' => 1]);
        Schema::create('catalog_nodes', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 24);
            $t->string('code', 100);
            $t->foreignId('parent_id')->nullable()->constrained('catalog_nodes')->restrictOnDelete();
            $t->json('labels');
            $t->boolean('regulated')->nullable();
            $t->json('rules')->nullable();
            $t->string('status', 24)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['kind', 'code']);
            $t->index(['kind', 'status', 'parent_id']);
        });
        Schema::create('catalog_entries', function (Blueprint $t) {
            $t->id();
            $t->string('code', 100)->unique();
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->string('status', 24)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('catalog_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->constrained('catalog_entries')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('admins')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('admins')->restrictOnDelete();
            $t->foreignId('published_by')->nullable()->constrained('admins')->restrictOnDelete();
            $t->string('status', 24)->default('draft');
            $t->string('domain_code', 100);
            $t->string('specialty_code', 100);
            $t->string('service_code', 100);
            $t->json('policy');
            $t->index(['status', 'domain_code', 'id'], 'catalog_domain_lookup');
            $t->index(['specialty_code', 'service_code'], 'catalog_service_lookup');
            $t->char('policy_hash', 64);
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['entry_id', 'version']);
            $t->index(['status', 'entry_id']);
        });
        Schema::create('expert_catalog_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('scope_id')->constrained('expert_verified_scopes')->restrictOnDelete();
            $t->foreignId('catalog_version_id')->constrained('catalog_versions')->restrictOnDelete();
            $t->string('jurisdiction_code', 100)->default('GLOBAL');
            $t->foreignId('reviewed_by')->constrained('admins')->restrictOnDelete();
            $t->string('evidence_type', 32);
            $t->unsignedBigInteger('evidence_id');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['scope_id', 'catalog_version_id', 'jurisdiction_code'], 'expert_catalog_grant_unique');
            $t->index(['catalog_version_id', 'jurisdiction_code', 'revoked_at'], 'catalog_grant_coverage');
        });
        Schema::create('case_service_scopes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $t->foreignId('catalog_version_id')->constrained('catalog_versions')->restrictOnDelete();
            $t->string('delivery_mode', 100);
            $t->json('jurisdiction_codes');
            $t->longText('answers');
            $t->boolean('confirmed')->default(false);
            $t->string('status', 40)->default('draft');
            $t->json('reason_codes')->nullable();
            $t->longText('policy_snapshot')->nullable();
            $t->timestamp('readiness_checked_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('detached_at')->nullable();
            $t->timestamps();
            $t->index(['case_id', 'detached_at', 'status'], 'case_service_scope_status');
            $t->index(['catalog_version_id', 'status'], 'case_service_catalog_status');
        });
        Schema::table('cases', function (Blueprint $t) {
            $t->unsignedSmallInteger('catalog_contract_version')->default(1);
            $t->string('readiness_status', 40)->default('draft');
            $t->char('readiness_confirmation', 64)->nullable();
            $t->timestamp('readiness_checked_at')->nullable();
            $t->index(['user_id', 'readiness_status', 'updated_at'], 'case_readiness_owner');
        });
        DB::table('cases')->where('status', 'cancelled')->update(['readiness_status' => 'cancelled']);
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $t) {
            $t->dropIndex('case_readiness_owner');
            $t->dropColumn(['catalog_contract_version', 'readiness_status', 'readiness_confirmation', 'readiness_checked_at']);
        });
        Schema::table('catalog_nodes', fn (Blueprint $t) => $t->dropForeign(['parent_id']));
        foreach (['case_service_scopes', 'expert_catalog_grants', 'catalog_versions', 'catalog_entries', 'catalog_nodes', 'catalog_locks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
