<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v7: the "from" price an Expert shows on matching cards (integer minor units). Informational only; proposals set the real price. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expert_offerings', function (Blueprint $table) {
            $table->unsignedBigInteger('price_from_minor')->nullable()->after('summary');
            $table->string('currency', 3)->default('AED')->after('price_from_minor');
        });
    }

    public function down(): void
    {
        Schema::table('expert_offerings', function (Blueprint $table) {
            $table->dropColumn(['price_from_minor', 'currency']);
        });
    }
};
