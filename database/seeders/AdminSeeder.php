<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminSeeder extends Seeder
{
    /**
     * Seed the initial administrator from environment-backed configuration.
     */
    public function run(): void
    {
        $name = trim((string) config('admin.initial.name'));
        $email = mb_strtolower(trim((string) config('admin.initial.email')));
        $password = (string) config('admin.initial.password');

        if ($name === '' || $email === '' || $password === '') {
            throw new RuntimeException(
                'Set ADMIN_NAME, ADMIN_EMAIL, and ADMIN_PASSWORD before running AdminSeeder.'
            );
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('ADMIN_EMAIL must contain a valid email address.');
        }

        if (mb_strlen($password) < 12) {
            throw new RuntimeException('ADMIN_PASSWORD must contain at least 12 characters.');
        }

        $admin = Admin::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
            ],
        );

        if ($admin->wasRecentlyCreated) {
            $admin->forceFill(['invitation_accepted_at' => now()])->save();
        }

        $this->call(AuthorizationSeeder::class);
        $admin->assignRole(AdminRole::SuperAdmin->value);

        $message = $admin->wasRecentlyCreated
            ? 'Initial administrator created successfully.'
            : 'An administrator with the configured email already exists.';

        $this->command?->info($message);
    }
}
