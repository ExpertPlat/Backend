<?php

namespace App\Services\Reviews;

use App\Exceptions\PrivacyException;
use App\Models\Admin;
use App\Models\Agreement;
use App\Models\ExpertReview;
use App\Models\User;
use App\Services\Privacy\AuditWriter;
use Illuminate\Support\Facades\DB;

/**
 * Reputation read model plus the gated write/moderation paths (docs/sprint4/reviews-contract.md).
 * Aggregates are derived from `published` rows only and are informational: nothing here feeds matching eligibility or ranking.
 */
class ExpertReviews
{
    /** The Agreement lifecycle has no completion yet (Sprint 11). This is the only status that will ever prove a delivered service. */
    private const COMPLETED_STATUS = 'completed';

    public function __construct(private AuditWriter $audit) {}

    /**
     * @param  list<int>  $expertIds
     * @return array<int, array{averageRating: float|null, reviewCount: int, ratingDistribution: array<string, int>}>
     */
    public function summaries(array $expertIds): array
    {
        $out = [];
        foreach ($expertIds as $id) {
            $out[$id] = ['averageRating' => null, 'reviewCount' => 0, 'ratingDistribution' => ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0]];
        }
        if ($expertIds === []) {
            return $out;
        }
        $rows = ExpertReview::query()->published()->whereIn('expert_id', $expertIds)
            ->selectRaw('expert_id, rating, COUNT(*) as total')->groupBy('expert_id', 'rating')->get();
        $sums = [];
        foreach ($rows as $row) {
            $out[$row->expert_id]['ratingDistribution'][(string) $row->rating] = (int) $row->total;
            $out[$row->expert_id]['reviewCount'] += (int) $row->total;
            $sums[$row->expert_id] = ($sums[$row->expert_id] ?? 0) + $row->rating * (int) $row->total;
        }
        foreach ($sums as $id => $sum) {
            $out[$id]['averageRating'] = round($sum / $out[$id]['reviewCount'], 1);
        }

        return $out;
    }

    /** @return array{averageRating: float|null, reviewCount: int, ratingDistribution: array<string, int>} */
    public function summary(int $expertId): array
    {
        return $this->summaries([$expertId])[$expertId];
    }

    /** @return array{eligible: bool, reasonCode: string|null, reviewId: int|null} */
    public function eligibility(User $user, Agreement $agreement): array
    {
        if ($agreement->user_id !== $user->id) {
            return ['eligible' => false, 'reasonCode' => 'SERVICE_NOT_COMPLETED', 'reviewId' => null];
        }
        $existing = ExpertReview::where('agreement_id', $agreement->id)->value('id');
        if ($existing !== null) {
            return ['eligible' => false, 'reasonCode' => 'ALREADY_REVIEWED', 'reviewId' => (int) $existing];
        }
        if ($agreement->status !== self::COMPLETED_STATUS) {
            return ['eligible' => false, 'reasonCode' => 'SERVICE_NOT_COMPLETED', 'reviewId' => null];
        }

        return ['eligible' => true, 'reasonCode' => null, 'reviewId' => null];
    }

    /** @param array{rating: int, body?: string|null} $d */
    public function create(User $user, Agreement $agreement, array $d): ExpertReview
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($user, $agreement, $d): ExpertReview {
            $locked = Agreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $state = $this->eligibility($user, $locked);
            if (! $state['eligible']) {
                throw $state['reasonCode'] === 'ALREADY_REVIEWED'
                    ? new PrivacyException('REVIEW_ALREADY_SUBMITTED', 'A review was already submitted for this service.')
                    : new PrivacyException('REVIEW_SERVICE_NOT_COMPLETED', 'The service must be completed before it can be reviewed.');
            }
            $review = new ExpertReview;
            $review->forceFill(['expert_id' => $locked->expert_id, 'user_id' => $user->id, 'case_id' => $locked->case_id, 'agreement_id' => $locked->id,
                'rating' => $d['rating'], 'body' => $this->clean($d['body'] ?? null), 'moderation_status' => ExpertReview::PENDING, 'version' => 1])->save();
            $this->audit->write($user, 'expert_review.created', 'expert_review', $review->id, null, ExpertReview::PENDING, null, ['reviewId' => $review->id, 'agreementId' => $locked->id, 'rating' => $review->rating]);

            return $review;
        });
    }

    /** @param array{rating: int, body?: string|null, expectedVersion: int} $d */
    public function update(User $user, int $reviewId, array $d): ExpertReview
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($user, $reviewId, $d): ExpertReview {
            $review = ExpertReview::where('user_id', $user->id)->lockForUpdate()->findOrFail($reviewId);
            if ($review->moderation_status !== ExpertReview::PENDING) {
                throw new PrivacyException('REVIEW_NOT_EDITABLE', 'Only a review that has not been moderated can be edited.');
            }
            if ($review->version !== $d['expectedVersion']) {
                throw new PrivacyException('RESOURCE_CONFLICT', 'The review changed. Reload and retry.');
            }
            $review->forceFill(['rating' => $d['rating'], 'body' => $this->clean($d['body'] ?? null), 'version' => $review->version + 1])->save();
            $this->audit->write($user, 'expert_review.updated', 'expert_review', $review->id, null, null, null, ['reviewId' => $review->id, 'rating' => $review->rating, 'toVersion' => $review->version]);

            return $review;
        });
    }

    public function moderate(Admin $admin, int $reviewId, string $status, int $expectedVersion, ?string $note): ExpertReview
    {
        return DB::transaction(function () use ($admin, $reviewId, $status, $expectedVersion, $note): ExpertReview {
            $review = ExpertReview::query()->lockForUpdate()->findOrFail($reviewId);
            if ($review->version !== $expectedVersion) {
                throw new PrivacyException('RESOURCE_CONFLICT', 'The review changed. Reload and retry.');
            }
            if (! in_array($status, ExpertReview::TRANSITIONS[$review->moderation_status] ?? [], true)) {
                throw new PrivacyException('REVIEW_INVALID_TRANSITION', 'That moderation transition is not allowed.');
            }
            $from = $review->moderation_status;
            $review->forceFill(['moderation_status' => $status, 'moderation_note' => $note, 'moderated_by_admin_id' => $admin->id, 'moderated_at' => now(),
                'published_at' => $status === ExpertReview::PUBLISHED ? ($review->published_at ?? now()) : $review->published_at, 'version' => $review->version + 1])->save();
            $this->audit->write($admin, 'expert_review.moderated', 'expert_review', $review->id, $from, $status, null, ['reviewId' => $review->id, 'toVersion' => $review->version]);

            return $review;
        });
    }

    /** Safe public reviewer name: first name plus last initial. Never an email or id. */
    public static function displayName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'SolveIt client';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts[0].' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.';
    }

    private function assertEnabled(): void
    {
        if (! config('reviews.write_enabled')) {
            throw new PrivacyException('REVIEWS_NOT_ENABLED', 'Reviews cannot be submitted yet.', 403);
        }
    }

    private function clean(?string $body): ?string
    {
        $body = $body === null ? null : trim(strip_tags($body));

        return $body === '' ? null : $body;
    }
}
