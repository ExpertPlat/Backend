<?php

namespace App\Http\Resources\User\Privacy;

use App\Enums\ContextStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContextResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        $deleted = $this->status === ContextStatus::Deleted;
        $version = $this->latestVersion;

        return ['id' => $this->id, 'domain' => $this->domain, 'country' => $this->country,
            'status' => $this->status->value, 'version' => $this->current_version,
            'schemaVersion' => $version?->schema_version, 'title' => $deleted ? null : ($version?->payload['title'] ?? null),
            'facts' => $this->when(! $deleted && ! $request->routeIs('user.contexts.index'), fn () => $version?->payload['facts'] ?? []),
            'conflictStatus' => $deleted ? null : $version?->conflict_status,
            'clarification' => $this->when(! $deleted && ! $request->routeIs('user.contexts.index'), fn () => $version?->clarification),
            'allowCaseReuse' => $this->allow_case_reuse, 'canUseInFutureCase' => $this->canUseInFutureCase(),
            'visibility' => 'private', 'createdAt' => $this->created_at?->toISOString(), 'updatedAt' => $this->updated_at?->toISOString()];
    }
}
