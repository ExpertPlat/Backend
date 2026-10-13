<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reviews\ReviewPresenter;
use App\Models\ExpertProfile;
use App\Models\ExpertReview;
use App\Services\Reviews\ExpertReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public, paginated list of PUBLISHED reviews of a publicly visible Expert. Nothing private is serialised. */
class ExpertReviewController extends Controller
{
    public function index(Request $request, ExpertProfile $profile, ExpertReviews $reviews): JsonResponse
    {
        abort_unless($profile->isPubliclyVisible(), 404);
        $perPage = min(50, max(1, (int) $request->query('perPage', config('reviews.public_per_page'))));
        $page = ExpertReview::query()->published()->where('expert_id', $profile->expert_id)->with('author:id,name')
            ->orderByDesc('published_at')->orderByDesc('id')->paginate($perPage)->withQueryString();

        return response()->json(['status' => true, 'message' => 'Expert reviews retrieved successfully.', 'data' => [
            'summary' => $reviews->summary($profile->expert_id),
            'items' => collect($page->items())->map(fn (ExpertReview $r) => ReviewPresenter::public($r))->values()->all(),
            'pagination' => ['currentPage' => $page->currentPage(), 'perPage' => $page->perPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]]);
    }
}
