<?php

namespace App\Console\Commands;

use App\Services\Payments\Fundings;
use Illuminate\Console\Command;

/** Scheduler: expires overdue Funding windows (syncing with Stripe first). Idempotent. */
class ExpireFundings extends Command
{
    protected $signature = 'finance:expire';

    protected $description = 'Expire overdue funding windows';

    public function handle(Fundings $fundings): int
    {
        $this->info('Expired: '.$fundings->expireDue());

        return self::SUCCESS;
    }
}
