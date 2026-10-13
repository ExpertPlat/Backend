<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\Expert\Profile\PublicExpertProfileResource;
use App\Models\ExpertProfile;
use App\Services\Reviews\ExpertReviews;
use Illuminate\Http\JsonResponse;

class ExpertProfileController extends Controller
{
    public function show(ExpertProfile $profile, ExpertReviews $reviews): JsonResponse
    {
        $profile->load([
            'expert:id,name,country,is_active,kyc_status',
            'expert.availability',
            'expert.verifiedScopes',
        ]);

        $isVisible = $profile->isPubliclyVisible();

        abort_unless($isVisible, 404);

        return response()->json([
            'status' => true,
            'message' => 'Public expert profile retrieved successfully.',
            'data' => ['expert' => (new PublicExpertProfileResource($profile))->resolve() + ['reputation' => $reviews->summary($profile->expert_id)]],
        ]);
    }
}
