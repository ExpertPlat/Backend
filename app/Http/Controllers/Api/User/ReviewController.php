<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Api\Concerns\MatchingResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\ReviewRequest;
use App\Http\Resources\Reviews\ReviewPresenter;
use App\Models\Agreement;
use App\Services\Privacy\ActorIdempotency;
use App\Services\Reviews\ExpertReviews;
use Illuminate\Http\JsonResponse;

/** Review write path (feature-gated; see docs/sprint4/reviews-contract.md). Users act only on their own Agreements/reviews. */
class ReviewController extends Controller
{
    use MatchingResponses;

    public function eligibility(ReviewRequest $r, int $case, int $agreement, ExpertReviews $reviews): JsonResponse
    {
        $row = $this->agreement($r, $case, $agreement);

        return $this->item($reviews->eligibility($r->user(), $row) + ['writeEnabled' => (bool) config('reviews.write_enabled')]);
    }

    public function store(ReviewRequest $r, int $case, int $agreement, ExpertReviews $reviews, ActorIdempotency $idempotency): JsonResponse
    {
        $row = $this->agreement($r, $case, $agreement);

        return $idempotency->run($r, $r->validated(), fn () => $this->item(ReviewPresenter::own($reviews->create($r->user(), $row, $r->validated())), 'Review submitted for moderation.', 201));
    }

    public function update(ReviewRequest $r, int $review, ExpertReviews $reviews): JsonResponse
    {
        return $this->item(ReviewPresenter::own($reviews->update($r->user(), $review, $r->validated())), 'Review updated.');
    }

    private function agreement(ReviewRequest $r, int $case, int $agreement): Agreement
    {
        $caseRow = $r->user()->cases()->findOrFail($case);

        return Agreement::where(['case_id' => $caseRow->id, 'user_id' => $r->user()->id])->findOrFail($agreement);
    }
}
