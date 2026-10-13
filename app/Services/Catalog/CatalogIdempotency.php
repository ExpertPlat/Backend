<?php

namespace App\Services\Catalog;

use App\Exceptions\PrivacyException;
use App\Models\IdempotencyRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogIdempotency
{
    public function run($request, \Closure $action)
    {
        $key = $request->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'A unique 16..128 character key is required.']);
        }
        $operation = $request->route()->getName().'|'.implode(':', $request->route()->parameters());
        $hash = hash('sha256', json_encode($this->canonical($request->validated()), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $action, $key, $operation, $hash) {
            CatalogMutex::lock();
            $attributes = ['actor_type' => 'admin', 'actor_id' => $request->user()->id, 'operation' => $operation, 'key_hash' => hash('sha256', $key)];
            $r = IdempotencyRecord::where($attributes)->first();
            if ($r && $r->expires_at->isPast()) {
                $r->delete();
                $r = null;
            }
            if ($r) {
                if (! hash_equals($r->request_hash, $hash)) {
                    throw new PrivacyException('IDEMPOTENCY_CONFLICT', 'Request key already used.');
                }

                return response()->json($r->response_body, $r->response_status)->header('Idempotency-Replayed', 'true');
            }
            $response = $action();
            $r = new IdempotencyRecord;
            $r->forceFill($attributes + ['request_hash' => $hash, 'response_body' => $response->getData(true), 'response_status' => $response->getStatusCode(), 'expires_at' => now()->addHours(24)])->save();

            return $response;
        });
    }

    private function canonical(array $a): array
    {
        if (! array_is_list($a)) {
            ksort($a);
        }foreach ($a as &$v) {
            if (is_array($v)) {
                $v = $this->canonical($v);
            }
        }

        return $a;
    }
}
