<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Expert;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Trial/investor deployments: builds the demo world (services, five-country expert coverage, demo users) on any
 * environment, including production. Idempotent — safe to run on every deploy. Remove it with `demo:disable`.
 */
class SeedInvestorDemo extends Command
{
    protected $signature = 'demo:seed-investor {--no-admins : Do not create demo admin accounts} {--force : Required outside local/testing}';

    protected $description = 'Seed the trial demo world (demo experts, users, general consultation, 5-country coverage). Trial use only.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('This creates accounts with a PUBLIC demo password. Re-run with --force to confirm this is a trial deployment.');

            return self::FAILURE;
        }
        app(DemoSeeder::class)->setCommand($this)->setContainer(app())
            ->seedWorld(! $this->option('no-admins'), app()->environment(['local', 'testing']));
        $this->newLine();
        $this->warn('TRIAL ONLY: demo accounts use the public password '.DemoSeeder::PASSWORD.'. Run `php artisan demo:disable` before real launch.');

        return self::SUCCESS;
    }
}
