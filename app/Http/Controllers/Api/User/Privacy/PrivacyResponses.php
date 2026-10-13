<?php

namespace App\Http\Controllers\Api\User\Privacy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

trait PrivacyResponses
{
    private function item(mixed $item, string $message = 'Request completed successfully.', int $status = 200): JsonResponse
    {
        return response()->json(['status' => true, 'message' => $message, 'data' => ['item' => $item]], $status);
    }

    private function listing(Builder $query, array $data, string $resource, array $columns): JsonResponse
    {
        $sort = $columns[$data['sortBy'] ?? array_key_first($columns)];
        $p = $query->orderBy($sort, $data['sortDirection'] ?? 'desc')->orderBy('id', $data['sortDirection'] ?? 'desc')
            ->paginate($data['perPage'] ?? 20)->withQueryString();

        return response()->json(['status' => true, 'message' => 'Records retrieved successfully.', 'data' => [
            'items' => $resource::collection($p->items()), 'pagination' => ['currentPage' => $p->currentPage(),
                'perPage' => $p->perPage(), 'lastPage' => $p->lastPage(), 'total' => $p->total()],
        ]]);
    }
}
