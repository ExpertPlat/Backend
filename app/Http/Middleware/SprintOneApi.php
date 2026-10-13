<?php

namespace App\Http\Middleware;

use App\Exceptions\PrivacyException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SprintOneApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('privacy_request_id', (string) Str::uuid());
        try {
            $response = $next($request);
        } catch (PrivacyException $exception) {
            $response = response()->json([
                'status' => false, 'code' => $exception->errorCode, 'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }
        // Framework-rendered exceptions and pre-existing auth middleware are also normalized,
        // but only for explicitly enrolled Sprint 1 routes.
        $codes = [401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'RESOURCE_NOT_FOUND', 409 => 'RESOURCE_CONFLICT', 422 => 'VALIDATION_FAILED', 429 => 'RATE_LIMITED'];
        // Sprint 3: a deliberately raised 503 (AI provider / feature unavailable) is a PrivacyException with a stable safe
        // `code`; it is not an internal error and must stay distinguishable. Every other 5xx is still masked.
        $safeUnavailable = $response->getStatusCode() === 503 && $response instanceof JsonResponse && is_string($response->getData(true)['code'] ?? null);
        if ($response->getStatusCode() >= 500 && ! $safeUnavailable) {
            $response = response()->json(['status' => false, 'code' => 'INTERNAL_ERROR', 'message' => 'The request could not be completed. Please retry later.'], 500);
        } elseif ($response instanceof JsonResponse && isset($codes[$response->getStatusCode()])) {
            $body = $response->getData(true);
            $body['status'] = false;
            $body['code'] ??= $codes[$response->getStatusCode()];
            if ($response->getStatusCode() === 404) {
                $body = ['status' => false, 'code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.'];
            }
            // Do not forward stack traces even when APP_DEBUG is enabled.
            $response->setData(array_intersect_key($body, array_flip(['status', 'code', 'message', 'errors', 'email_verified'])));
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Request-ID', $request->attributes->get('privacy_request_id'));

        return $response;
    }
}
