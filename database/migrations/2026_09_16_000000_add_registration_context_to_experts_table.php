<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experts', function (Blueprint $table) {
            $table->string('country', 16)->nullable()->after('email');
            $table->string('language', 16)->nullable()->after('country');
            $table->string('domain', 50)->nullable()->after('language')->index();
        });
    }

    public function down(): void
    {
        Schema::table('experts', function (Blueprint $table) {
            $table->dropIndex(['domain']);
            $table->dropColumn(['country', 'language', 'domain']);
        });
    }
};
