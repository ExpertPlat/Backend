<?php

namespace App\Services\Payments;

use App\Models\Admin;
use App\Models\Expert;
use App\Models\User;
use App\Notifications\Payments\FinanceNotice;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends queued finance notices. A notification problem must never fail or roll back a financial transition,
 * so every send is isolated (the notification itself is queued and only dispatched after the transaction commits).
 */
class FinanceNotifier
{
    /** @param array<string, int|string> $refs */
    public function user(int $userId, string $type, array $refs): void
    {
        $this->safely(fn () => User::find($userId)?->notify(new FinanceNotice($type, $refs)));
    }

    /** @param array<string, int|string> $refs */
    public function expert(int $expertId, string $type, array $refs): void
    {
        $this->safely(fn () => Expert::find($expertId)?->notify(new FinanceNotice($type, $refs)));
    }

    /** @param array<string, int|string> $refs */
    public function admins(string $permission, string $type, array $refs): void
    {
        $this->safely(function () use ($permission, $type, $refs): void {
            $admins = Admin::query()->where('is_active', true)->permission($permission)->get();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new FinanceNotice($type, $refs));
            }
        });
    }

    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
