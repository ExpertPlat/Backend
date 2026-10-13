<?php

namespace App\Http\Controllers\Api\Expert\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\Profile\UpdateExpertProfileRequest;
use App\Http\Requests\Expert\Profile\UploadExpertAvatarRequest;
use App\Http\Resources\Expert\Profile\AvailabilityResource;
use App\Http\Resources\Expert\Profile\ProfessionalProfileResource;
use App\Http\Resources\Expert\Profile\PublicExpertProfileResource;
use App\Http\Resources\Expert\Profile\VerifiedScopeResource;
use App\Models\Expert;
use App\Models\ExpertProfile;
use App\Services\Expert\ExpertProfileManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfessionalProfileController extends Controller
{
    public function __construct(private readonly ExpertProfileManager $profiles) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();

        return $this->workspaceResponse($expert, 'Expert professional profile retrieved successfully.');
    }

    public function update(UpdateExpertProfileRequest $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $this->profiles->updateProfile($expert, $request->validated());

        return $this->workspaceResponse($expert, 'Expert professional profile updated successfully.');
    }

    public function uploadAvatar(UploadExpertAvatarRequest $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $this->profiles->uploadAvatar($expert, $request->file('avatar'));

        return $this->workspaceResponse($expert, 'Expert avatar updated successfully.');
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $this->profiles->deleteAvatar($expert);

        return $this->workspaceResponse($expert, 'Expert avatar removed successfully.');
    }

    public function preview(Request $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $profile = $this->loadPublicProfile($expert);

        return response()->json([
            'status' => true,
            'message' => 'Expert profile preview retrieved successfully.',
            'data' => ['expert' => new PublicExpertProfileResource($profile)],
        ]);
    }

    public function scopes(Request $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $scopes = $expert->verifiedScopes()->latest('valid_from')->latest('id')->get();

        return response()->json([
            'status' => true,
            'message' => 'Expert verified scopes retrieved successfully.',
            'data' => ['verifiedScopes' => VerifiedScopeResource::collection($scopes)],
        ]);
    }

    public function publish(Request $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $this->profiles->publish($expert);

        return $this->workspaceResponse($expert, 'Expert professional profile published successfully.');
    }

    public function unpublish(Request $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $this->profiles->unpublish($expert);

        return $this->workspaceResponse($expert, 'Expert professional profile unpublished successfully.');
    }

    private function workspaceResponse(Expert $expert, string $message): JsonResponse
    {
        $profile = $this->profiles->profile($expert);
        $availability = $this->profiles->availability($expert);
        $scopes = $expert->verifiedScopes()->latest('valid_from')->latest('id')->get();
        $missing = $this->profiles->missingRequirements($expert, $profile, $availability);

        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => [
                'account' => [
                    'name' => $expert->name,
                    'email' => $expert->email,
                    'country' => $expert->country,
                    'language' => $expert->language,
                    'kycStatus' => $expert->kyc_status->value,
                ],
                'profile' => new ProfessionalProfileResource($profile),
                'verifiedScopes' => VerifiedScopeResource::collection($scopes),
                'availability' => new AvailabilityResource($availability),
                'publication' => [
                    'canPublish' => $missing === [],
                    'missingRequirements' => $missing,
                    'publiclyVisible' => $profile->is_published && $missing === [],
                    'publicPath' => '/experts/'.$profile->slug,
                ],
            ],
        ]);
    }

    private function loadPublicProfile(Expert $expert): ExpertProfile
    {
        $profile = $this->profiles->profile($expert);
        $expert->setRelation('verifiedScopes', $expert->verifiedScopes()->latest('valid_from')->get());
        $expert->setRelation('availability', $this->profiles->availability($expert));
        $profile->setRelation('expert', $expert);

        return $profile;
    }
}
