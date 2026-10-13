<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_offerings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('expert_id')->constrained('experts')->restrictOnDelete();
            $t->foreignId('catalog_entry_id')->constrained('catalog_entries')->restrictOnDelete();
            $t->json('delivery_modes');
            $t->json('languages');
            $t->json('jurisdiction_codes')->nullable();
            $t->string('summary', 500)->nullable();
            $t->string('status', 16)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['expert_id', 'catalog_entry_id'], 'expert_offering_unique');
            $t->index(['catalog_entry_id', 'status'], 'expert_offering_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_offerings');
    }
};
