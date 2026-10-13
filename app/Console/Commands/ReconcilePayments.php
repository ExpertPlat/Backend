<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentReconciler;
use Illuminate\Console\Command;

/** Scheduler: reconciles stale provider state (missed webhooks, unsubmitted refunds, Connect accounts). Idempotent. */
class ReconcilePayments extends Command
{
    protected $signature = 'finance:reconcile';

    protected $description = 'Reconcile stale payment, refund and Stripe account state with the provider';

    public function handle(PaymentReconciler $reconciler): int
    {
        $r = $reconciler->run();
        $this->info(sprintf('Fundings: %d, refunds: %d, accounts: %d', $r['fundings'], $r['refunds'], $r['accounts']));

        return self::SUCCESS;
    }
}
