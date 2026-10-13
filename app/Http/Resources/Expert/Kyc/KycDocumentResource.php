<?php

namespace App\Http\Resources\Expert\Kyc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $adminRequest = $request->is('api/admin/*');
        $route = $adminRequest ? 'admin.kyc.documents.show' : 'expert.kyc.documents.show';
        $parameters = $adminRequest
            ? ['application' => $this->application_id, 'document' => $this->id]
            : ['document' => $this->id];

        return [
            'id' => $this->id,
            'type' => $this->document_type->value,
            'originalName' => $this->original_name,
            'extension' => $this->extension,
            'mimeType' => $this->mime_type,
            'size' => $this->size,
            'reviewed' => $this->reviewed_at !== null,
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'downloadUrl' => route($route, $parameters),
        ];
    }
}
