<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Controllers\Api\User\Privacy\PrivacyResponses;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/** Envelope helpers shared by the Sprint 3 controllers (same `status/message/data` shape as Sprint 1/2). */
trait MatchingResponses
{
    use PrivacyResponses;

    /** @param Closure(mixed): array<string, mixed> $map */
    private function page(Builder|Relation $query, array $data, Closure $map): JsonResponse
    {
        $p = $query->paginate($data['perPage'] ?? 20)->withQueryString();

        return response()->json(['status' => true, 'message' => 'Records retrieved successfully.', 'data' => [
            'items' => collect($p->items())->map($map)->values()->all(),
            'pagination' => ['currentPage' => $p->currentPage(), 'perPage' => $p->perPage(), 'lastPage' => $p->lastPage(), 'total' => $p->total()],
        ]]);
    }

    /** @param list<array<string, mixed>> $items */
    private function items(array $items): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Records retrieved successfully.', 'data' => ['items' => $items]]);
    }

    private function idempotencyKey(): string
    {
        $key = request()->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['Provide a unique 16 to 128 character request key.']]);
        }

        return $key;
    }
}
