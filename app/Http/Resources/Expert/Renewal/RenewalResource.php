<?php

namespace App\Http\Resources\Expert\Renewal;

use App\Http\Resources\Expert\Profile\VerifiedScopeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RenewalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->resource->relationLoaded('submissions');

        return [
            'id' => $this->id, 'scopeId' => $this->scope_id, 'status' => $this->status->value, 'version' => $this->version,
            'currentSubmissionId' => $this->current_submission_id, 'replacementScopeId' => $this->replacement_scope_id,
            'createdAt' => $this->created_at->toISOString(), 'updatedAt' => $this->updated_at->toISOString(),
            'submittedAt' => $this->submitted_at?->toISOString(), 'decidedAt' => $this->decided_at?->toISOString(),
            $this->mergeWhen($detail, fn () => ['scope' => (new VerifiedScopeResource($this->scope))->resolve(), 'nextReviewAt' => $this->scope->next_review_at?->toDateString(), 'feedback' => $this->feedback, 'history' => $this->history,
                'submissions' => $this->submissions->map(fn ($item) => ['id' => $item->id, 'evidence' => $item->evidence,
                    'file' => ['mimeType' => $item->mime_type, 'size' => $item->size], 'createdAt' => $item->created_at->toISOString()])->all()]),
        ];
    }
}
