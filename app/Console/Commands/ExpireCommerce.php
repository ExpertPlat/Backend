<?php

namespace App\Console\Commands;

use App\Services\Commerce\Bookings;
use App\Services\Commerce\Proposals;
use Illuminate\Console\Command;

class ExpireCommerce extends Command
{
    protected $signature = 'commerce:expire';

    protected $description = 'Expire overdue proposals and slot holds, release held capacity and slots, and notify. Idempotent.';

    public function handle(Proposals $proposals, Bookings $bookings): int
    {
        $this->info('Proposals expired: '.$proposals->expireDue().'; holds expired: '.$bookings->expireDueHolds());

        return self::SUCCESS;
    }
}
