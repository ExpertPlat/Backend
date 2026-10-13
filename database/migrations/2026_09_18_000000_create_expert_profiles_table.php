<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expert_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 120)->unique();
            $table->string('professional_title')->nullable();
            $table->text('bio')->nullable();
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->json('specialties')->nullable();
            $table->json('public_languages')->nullable();
            $table->string('avatar_disk', 32)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('avatar_original_name')->nullable();
            $table->string('avatar_mime_type', 100)->nullable();
            $table->unsignedBigInteger('avatar_size')->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_profiles');
    }
};
