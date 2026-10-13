<?php

namespace App\Http\Resources\Cases;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaseDocumentVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'title' => $this->title, 'category' => $this->category, 'mimeType' => $this->mime_type, 'size' => $this->size, 'checksum' => $this->checksum, 'scanStatus' => $this->scan_status->value, 'scanReason' => $this->scan_reason, 'scannedAt' => $this->scanned_at?->toISOString(), 'createdAt' => $this->created_at?->toISOString()];
    }
}
