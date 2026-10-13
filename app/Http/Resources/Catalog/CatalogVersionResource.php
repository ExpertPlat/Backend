<?php

namespace App\Http\Resources\Catalog;

use App\Models\CatalogVersion;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogVersion */
class CatalogVersionResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'catalogEntryId' => $this->entry_id, 'catalogVersion' => $this->version, 'revision' => $this->revision, 'status' => $this->status, 'policy' => $this->policy, 'policyHash' => $this->policy_hash, 'reviewedAt' => $this->reviewed_at?->toISOString(), 'publishedAt' => $this->published_at?->toISOString()];
    }
}
