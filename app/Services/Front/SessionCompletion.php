<?php

namespace App\Services\Front;

use App\Models\Agreement;
use App\Models\Booking;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\DB;

/**
 * A confirmed session whose end time has passed is `completed`; a funded Agreement whose session completed is
 * `completed` too. This is what opens the User's review (ExpertReviews requires a completed Agreement).
 * Runs on the scheduler (commerce:complete-sessions) and lazily before session/agreement reads.
 */
class SessionCompletion
{
    public function __construct(private AuditWriter $audit) {}

    public function run(?int $limit = 200): int
    {
        $ids = Booking::where('status', Booking::CONFIRMED)->where('end_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        $done = 0;
        foreach ($ids as $id) {
            $done += DB::transaction(function () use ($id) {
                $b = Booking::whereKey($id)->lockForUpdate()->first();
                if (! $b || $b->status !== Booking::CONFIRMED || $b->end_at->isFuture()) {
                    return 0;
                }
                $b->forceFill(['status' => 'completed', 'active_key' => null, 'version' => $b->version + 1])->save();
                $this->audit->write(null, 'booking.completed', 'booking', $b->id, Booking::CONFIRMED, 'completed');
                $a = Agreement::whereKey($b->agreement_id)->lockForUpdate()->first();
                if ($a && $a->status === Agreement::FUNDED) {
                    $a->forceFill(['status' => 'completed', 'version' => $a->version + 1])->save();
                    $this->audit->write(null, 'agreement.completed', 'agreement', $a->id, Agreement::FUNDED, 'completed');
                }

                return 1;
            });
        }

        return $done;
    }
}
