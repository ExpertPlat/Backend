<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7 frontend intake: the AI (or rules) reading of a Case — summary, key facts, ranked service scopes and the
 * multiple-choice questions experts need — plus the User's answers. One current row per Case. Text is encrypted at rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->unique()->constrained('cases')->cascadeOnDelete();
            $table->string('source', 20); // ai | rules
            $table->string('model', 100)->nullable();
            $table->text('summary')->nullable();
            $table->text('facts')->nullable();
            $table->text('scope_notes')->nullable(); // per catalog entry: why, score, priority
            $table->text('questions')->nullable();
            $table->text('answers')->nullable();
            $table->unsignedInteger('question_version')->default(1);
            $table->string('suitability', 40)->default('suitable');
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_analyses');
    }
};
