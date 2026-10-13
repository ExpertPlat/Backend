<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\AdminReviewRequest;
use App\Http\Resources\Reviews\ReviewPresenter;
use App\Models\ExpertReview;
use App\Services\Reviews\ExpertReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Minimum moderation foundation. Each action requires its explicit permission (no role-name checks). */
class ExpertReviewController extends Controller
{
    use MatchingResponses;

    public function index(AdminReviewRequest $r): JsonResponse
    {
        Gate::authorize('reviews.viewAny');
        $d = $r->validated();
        $q = ExpertReview::query()->orderByDesc('id');
        foreach (['status' => 'moderation_status', 'expertId' => 'expert_id'] as $field => $column) {
            if (isset($d[$field])) {
                $q->where($column, $d[$field]);
            }
        }

        return $this->page($q, $d, fn (ExpertReview $review) => ReviewPresenter::admin($review));
    }

    public function moderate(AdminReviewRequest $r, int $review, ExpertReviews $reviews): JsonResponse
    {
        Gate::authorize('reviews.moderate');
        $d = $r->validated();

        return $this->item(ReviewPresenter::admin($reviews->moderate($r->user(), $review, $d['status'], $d['expectedVersion'], $d['note'] ?? null)), 'Review moderation updated.');
    }
}
