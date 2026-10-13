<?php

namespace App\Http\Front;

use Illuminate\Http\JsonResponse;

/** `{ status: true, message, data }` where data is the resource itself (or a plain array for collections). */
trait FrontResponses
{
    /** Resolve {ar, en} system text to the request language (User/Expert). The Admin dashboard edits both languages, so it keeps pairs. */
    protected function resolvesText(): bool
    {
        return ! property_exists($this, 'keepTextPairs') || ! $this->keepTextPairs;
    }

    protected function ok(mixed $data, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json(['status' => true, 'message' => $message, 'data' => $this->resolvesText() ? Front::deep($data) : $data], $status, [], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Laravel-style paginated collection: `{ data: [...], meta: { current_page, last_page, per_page, total } }`. */
    protected function paged(\Illuminate\Contracts\Pagination\LengthAwarePaginator $p, callable $map, string $message = ''): JsonResponse
    {
        return response()->json(['status' => true, 'message' => $message, 'data' => $this->resolvesText() ? Front::deep(array_values(array_map($map, $p->items()))) : array_values(array_map($map, $p->items())),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()]], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
