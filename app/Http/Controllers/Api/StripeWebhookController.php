<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PrivacyException;
use App\Http\Controllers\Controller;
use App\Services\Payments\StripeWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unauthenticated by Sanctum, authenticated by the Stripe signature over the raw body. Errors never echo payloads or provider text.
 */
class StripeWebhookController extends Controller
{
    public function handle(Request $request, StripeWebhooks $webhooks): JsonResponse
    {
        try {
            $outcome = $webhooks->handle($request->getContent(), $request->header('Stripe-Signature'));
        } catch (PrivacyException $e) {
            return response()->json(['status' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()], $e->httpStatus);
        }

        return response()->json(['status' => true, 'message' => 'Event received.', 'data' => ['outcome' => $outcome]]);
    }
}
