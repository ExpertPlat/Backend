<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Expert\ExpertResource;
use App\Models\Expert;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AdminExpertController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Expert::class);

        return ExpertResource::collection(Expert::query()->latest('id')->paginate(25));
    }

    public function show(Expert $expert): ExpertResource
    {
        Gate::authorize('view', $expert);

        return new ExpertResource($expert);
    }
}
