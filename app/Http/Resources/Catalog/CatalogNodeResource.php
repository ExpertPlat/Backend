<?php

namespace App\Http\Resources\Catalog;

use App\Models\CatalogNode;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogNode */
class CatalogNodeResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'code' => $this->code, 'parentId' => $this->parent_id, 'labels' => $this->labels, 'regulated' => $this->regulated, 'status' => $this->status, 'version' => $this->version];
    }
}
