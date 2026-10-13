<?php

namespace App\Http\Resources\Reviews;

use App\Models\ExpertReview;
use App\Services\Reviews\ExpertReviews;

/** Public/private separation is explicit: the public shape never carries Case, reviewer identity beyond a display name, or moderation data. */
final class ReviewPresenter
{
    /** @return array<string, mixed> */
    public static function public(ExpertReview $r): array
    {
        return ['id' => $r->id, 'rating' => $r->rating, 'body' => $r->body, 'publishedAt' => $r->published_at?->toISOString(),
            'reviewer' => ['displayName' => ExpertReviews::displayName((string) $r->author?->name)]];
    }

    /** Author view of their own review. @return array<string, mixed> */
    public static function own(ExpertReview $r): array
    {
        return ['id' => $r->id, 'expertId' => $r->expert_id, 'caseId' => $r->case_id, 'agreementId' => $r->agreement_id, 'rating' => $r->rating, 'body' => $r->body,
            'status' => $r->moderation_status, 'version' => $r->version, 'publishedAt' => $r->published_at?->toISOString(), 'createdAt' => $r->created_at->toISOString()];
    }

    /** Admin-only view. @return array<string, mixed> */
    public static function admin(ExpertReview $r): array
    {
        return self::own($r) + ['userId' => $r->user_id, 'moderationNote' => $r->moderation_note, 'moderatedByAdminId' => $r->moderated_by_admin_id,
            'moderatedAt' => $r->moderated_at?->toISOString()];
    }
}
