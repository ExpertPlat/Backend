<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Expert;
use App\Models\User;
use Illuminate\Console\Command;

/** Turns the trial world off before real launch: demo accounts can no longer sign in and demo experts leave matching. */
class DisableInvestorDemo extends Command
{
    protected $signature = 'demo:disable';

    protected $description = 'Deactivate every demo account created by demo:seed-investor (launch checklist).';

    public function handle(): int
    {
        $experts = Expert::where('is_demo', true)->update(['is_active' => false]);
        $admins = Admin::where('email', 'like', '%@solveit.test')->update(['is_active' => false]);
        $users = User::whereIn('email', ['ahmed@example.com', 'investor@example.com'])->get();
        foreach ($users as $u) {
            $u->tokens()->delete();
        }
        foreach (Expert::where('is_demo', true)->get() as $e) {
            $e->tokens()->delete();
        }
        $this->info("Deactivated {$experts} demo experts and {$admins} demo admins; revoked tokens of {$users->count()} demo users.");
        $this->line('Delete the demo users manually if they should not remain.');

        return self::SUCCESS;
    }
}
