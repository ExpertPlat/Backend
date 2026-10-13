<?php

namespace App\Console\Commands;

use App\Services\Matching\Invitations;
use Illuminate\Console\Command;

class ExpireInvitations extends Command
{
    protected $signature = 'invitations:expire';

    protected $description = 'Expire overdue invitations and proposal windows, release their held capacity, revoke document access and notify.';

    public function handle(Invitations $invitations): int
    {
        $r = $invitations->expireDue();
        $this->info("Expired: {$r['expired']}; expiring notices: {$r['expiring']}; grants expired: {$r['grants']}");

        return self::SUCCESS;
    }
}
