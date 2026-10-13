<?php

namespace App\Http\Controllers\Api\User\Privacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\Privacy\UpdateProfileRequest;
use App\Http\Resources\User\Privacy\ProfileResource;
use App\Models\UserProfile;
use App\Services\Privacy\ProfileManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProfileController extends Controller
{
    use PrivacyResponses;

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', UserProfile::class);

        return $this->item(new ProfileResource($request->user()->load('profile')));
    }

    public function update(UpdateProfileRequest $request, ProfileManager $manager): JsonResponse
    {
        Gate::authorize('create', UserProfile::class);
        $manager->update($request->user(), $request->validated());

        return $this->item(new ProfileResource($request->user()->refresh()->load('profile')));
    }
}
