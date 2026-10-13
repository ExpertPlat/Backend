<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v7 envelope for older resources that wrap their payload in a named key (`data.availability`, `data.requests` …).
 * Usage: ->middleware('front:availability,requests'). A wrapped list becomes `data: [...]` (+ `meta` from `pagination`);
 * a wrapped object is lifted to the top of `data` and also kept under its key, so either reading works.
 */
class FrontEnvelope
{
    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() >= 300) {
            return $response;
        }
        $body = $response->getData(true);
        $data = $body['data'] ?? null;
        if (! is_array($data)) {
            return $response;
        }
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data) || ! is_array($data[$key])) {
                continue;
            }
            $inner = $data[$key];
            if (array_is_list($inner)) {
                $inner = array_map(fn ($row) => is_array($row) && isset($row['renewal']) && is_array($row['renewal']) ? $row + ['renewable' => (bool) ($row['renewal']['eligible'] ?? false)] : $row, $inner);
                if (isset($data['pagination'])) {
                    $p = $data['pagination'];
                    $body['meta'] = ['current_page' => $p['currentPage'] ?? 1, 'last_page' => $p['lastPage'] ?? 1, 'per_page' => $p['perPage'] ?? count($inner), 'total' => $p['total'] ?? count($inner)];
                }
                $body['data'] = $inner;
            } else {
                $body['data'] = $inner + array_diff_key($data, [$key => true, 'pagination' => true]) + [$key => $inner];
            }
            break;
        }

        return $response->setData($body);
    }
}
