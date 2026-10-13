<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sprint 4.1 (docs/sprint4/reviews-contract.md). Additive. Rows are never hard-deleted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_reviews', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('expert_id')->constrained('experts')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $t->foreignId('agreement_id')->unique()->constrained('agreements')->restrictOnDelete();
            $t->unsignedTinyInteger('rating');
            $t->text('body')->nullable();
            $t->string('moderation_status', 12)->default('pending');
            $t->text('moderation_note')->nullable();
            $t->foreignId('moderated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $t->timestamp('moderated_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['expert_id', 'moderation_status', 'published_at'], 'expert_reviews_public_idx');
            $t->index(['moderation_status', 'id'], 'expert_reviews_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_reviews');
    }
};
