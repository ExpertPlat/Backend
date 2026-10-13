<?php

namespace App\Jobs\Payments;

use App\Services\Payments\Refunds;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Submits an approved refund to Stripe. Idempotent: the Stripe idempotency key is deterministic and the refund row is re-checked under lock. */
class ProcessRefund implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $refundId)
    {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(Refunds $refunds): void
    {
        $refunds->submit($this->refundId);
    }
}
