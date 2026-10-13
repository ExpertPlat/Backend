<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v7: the short note the User writes to the Expert with an invitation (encrypted at rest by the model cast). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_invitations', function (Blueprint $table) {
            $table->text('message')->nullable()->after('brief_id');
        });
    }

    public function down(): void
    {
        Schema::table('case_invitations', function (Blueprint $table) {
            $table->dropColumn('message');
        });
    }
};
