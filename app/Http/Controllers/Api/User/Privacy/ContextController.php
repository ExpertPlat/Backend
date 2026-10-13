<?php

namespace App\Http\Controllers\Api\User\Privacy;

use App\Enums\ContextStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\Privacy\ContextRequest;
use App\Http\Requests\User\Privacy\ListPrivacyRequest;
use App\Http\Requests\User\Privacy\TransitionRequest;
use App\Http\Resources\User\Privacy\ContextResource;
use App\Http\Resources\User\Privacy\ContextVersionResource;
use App\Models\SpecializedContext;
use App\Services\Privacy\ContextManager;
use App\Services\Privacy\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ContextController extends Controller
{
    use PrivacyResponses;

    public function schemas(): JsonResponse
    {
        Gate::authorize('viewAny', SpecializedContext::class);

        return response()->json(['status' => true, 'message' => 'Private context schemas.', 'data' => ['items' => collect(config('context_schemas.domains'))
            ->map(fn ($keys, $domain) => ['domain' => $domain, 'schemaVersion' => 1, 'factKeys' => $keys, 'visibility' => 'private'])->values()]]);
    }

    public function index(ListPrivacyRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SpecializedContext::class);
        $data = $request->validated();
        $query = $request->user()->contexts()->getQuery()->with('latestVersion')->where('status', '!=', 'deleted');
        foreach (['status', 'domain', 'country'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return $this->listing($query, $data, ContextResource::class, ['updatedAt' => 'updated_at', 'createdAt' => 'created_at']);
    }

    public function store(ContextRequest $request, ContextManager $manager, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('create', SpecializedContext::class);

        return $idempotency->run($request, $request->validated(), fn () => $this->item(new ContextResource($manager->create($request->user(), $request->validated())), 'Context created.', 201));
    }

    public function show(Request $request, int $context): JsonResponse
    {
        $record = $this->owned($request, $context);
        Gate::authorize('view', $record);

        return $this->item(new ContextResource($record));
    }

    public function update(ContextRequest $request, int $context, ContextManager $manager): JsonResponse
    {
        Gate::authorize('update', $this->owned($request, $context));

        return $this->item(new ContextResource($manager->change($request->user(), $context, $request->validated())));
    }

    public function versions(ListPrivacyRequest $request, int $context): JsonResponse
    {
        $record = $this->owned($request, $context);
        Gate::authorize('view', $record);

        return $this->listing($record->versions()->getQuery(), $request->validated(), ContextVersionResource::class, ['version' => 'version']);
    }

    public function archive(TransitionRequest $request, int $context, ContextManager $manager, Idempotency $idempotency): JsonResponse
    {
        return $this->transition($request, $context, ContextStatus::Archived, $manager, $idempotency);
    }

    public function restore(TransitionRequest $request, int $context, ContextManager $manager, Idempotency $idempotency): JsonResponse
    {
        return $this->transition($request, $context, ContextStatus::Active, $manager, $idempotency);
    }

    public function destroy(TransitionRequest $request, int $context, ContextManager $manager, Idempotency $idempotency): JsonResponse
    {
        // Include own tombstones for replay authorization; the manager still prevents a second deletion.
        $record = $request->user()->contexts()->findOrFail($context);
        Gate::authorize('update', $record);

        return $idempotency->run($request, $request->validated(), fn () => $this->item(new ContextResource($manager->change($request->user(), $context, $request->validated(), ContextStatus::Deleted))));
    }

    private function transition(TransitionRequest $request, int $id, ContextStatus $status, ContextManager $manager, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('update', $this->owned($request, $id));

        return $idempotency->run($request, $request->validated(), fn () => $this->item(new ContextResource($manager->change($request->user(), $id, $request->validated(), $status))));
    }

    private function owned(Request $request, int $id): SpecializedContext
    {
        return $request->user()->contexts()->with('latestVersion')->where('status', '!=', 'deleted')->findOrFail($id);
    }
}
