<?php

namespace App\Http\Resources\User\Privacy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContextVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        return ['version' => $this->version, 'schemaVersion' => $this->schema_version, 'payload' => $this->payload,
            'conflictStatus' => $this->conflict_status, 'clarification' => $this->clarification,
            'supersedesVersion' => $this->supersedes_version, 'createdAt' => $this->created_at->toISOString()];
    }
}
