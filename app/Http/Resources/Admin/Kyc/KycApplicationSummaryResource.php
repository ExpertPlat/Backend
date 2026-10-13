<?php

namespace App\Http\Resources\Admin\Kyc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycApplicationSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'attemptNumber' => $this->attempt_number,
            'status' => $this->status->value,
            'expert' => [
                'id' => $this->expert->id,
                'name' => $this->expert->name,
                'email' => $this->expert->email,
            ],
            'domain' => $this->domain,
            'jurisdiction' => $this->jurisdiction,
            'documentsCount' => $this->documents_count,
            'submittedAt' => $this->submitted_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
