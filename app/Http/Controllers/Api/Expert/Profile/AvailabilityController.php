<?php

namespace App\Http\Controllers\Api\Expert\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\Profile\UpdateExpertAvailabilityRequest;
use App\Http\Resources\Expert\Profile\AvailabilityResource;
use App\Models\Expert;
use App\Services\Expert\ExpertProfileManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(private readonly ExpertProfileManager $profiles) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();

        return response()->json([
            'status' => true,
            'message' => 'Expert availability retrieved successfully.',
            'data' => ['availability' => new AvailabilityResource($this->profiles->availability($expert))],
        ]);
    }

    public function update(UpdateExpertAvailabilityRequest $request): JsonResponse
    {
        /** @var Expert $expert */
        $expert = $request->user();
        $availability = $this->profiles->updateAvailability($expert, $request->validated());

        return response()->json([
            'status' => true,
            'message' => 'Expert availability updated successfully.',
            'data' => ['availability' => new AvailabilityResource($availability)],
        ]);
    }
}
