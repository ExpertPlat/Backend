<?php

namespace App\Http\Controllers\Api\User\Privacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\Privacy\UpdatePreferencesRequest;
use App\Http\Resources\User\Privacy\PreferenceResource;
use App\Models\UserPreference;
use App\Services\Privacy\ProfileManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PreferenceController extends Controller
{
    use PrivacyResponses;

    public function show(Request $request, ProfileManager $manager): JsonResponse
    {
        Gate::authorize('viewAny', UserPreference::class);

        return $this->item(new PreferenceResource($manager->preferences($request->user())));
    }

    public function update(UpdatePreferencesRequest $request, ProfileManager $manager): JsonResponse
    {
        Gate::authorize('create', UserPreference::class);

        return $this->item(new PreferenceResource($manager->updatePreferences($request->user(), $request->validated())));
    }
}
