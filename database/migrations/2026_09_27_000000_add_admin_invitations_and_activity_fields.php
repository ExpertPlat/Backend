<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->timestamp('invitation_accepted_at')->nullable()->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('invitation_accepted_at');
        });

        DB::table('admins')->whereNull('invitation_accepted_at')->update([
            'invitation_accepted_at' => now(),
        ]);

        Schema::create('admin_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->unique()->constrained('admins')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invitations');

        Schema::table('admins', function (Blueprint $table): void {
            $table->dropColumn(['invitation_accepted_at', 'last_login_at']);
        });
    }
};
