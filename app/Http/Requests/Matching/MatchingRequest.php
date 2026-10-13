<?php

namespace App\Http\Requests\Matching;

use App\Http\Requests\Cases\CaseRequest;
use Illuminate\Validation\Rule;

/** User-realm Case-bound Sprint 3 requests. Ownership/policy checks come from CaseRequest (case route parameter). */
class MatchingRequest extends CaseRequest
{
    public function rules(): array
    {
        $action = $this->routeIs('v2.user.cases.*') ? last(explode('.', $this->route()->getName())) : '';
        $group = explode('.', (string) $this->route()->getName())[3] ?? '';
        if ($this->isMethod('GET')) {
            return ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'], 'status' => ['sometimes', Rule::in(['pending', 'accepted', 'declined', 'expired', 'revoked'])]];
        }
        $version = ['expectedVersion' => ['required', 'integer', 'min:1']];

        return match ($group.'.'.$action) {
            'ai-questions.store' => $version + ['regenerate' => ['sometimes', 'boolean']],
            'ai-questions.respond' => $version + ['responses' => ['required', 'array', 'min:1', 'max:8'], 'responses.*' => ['required', 'array:questionId,action,answer'],
                'responses.*.questionId' => ['required', 'integer', 'min:1'], 'responses.*.action' => ['required', Rule::in(['answer', 'skip', 'reject'])],
                'responses.*.answer' => ['required_if:responses.*.action,answer', 'nullable', 'string', 'min:1', 'max:2000']],
            'matching-runs.store' => $version,
            'briefs.preview' => $version + $this->selection(),
            'briefs.store' => $version + $this->selection() + ['approved' => ['required', 'accepted'], 'contentHash' => ['required', 'string', 'size:64']],
            'invitations.store' => ['matchingRunId' => ['required', 'integer', 'min:1'], 'candidateId' => ['required', 'integer', 'min:1'], 'briefId' => ['required', 'integer', 'min:1']],
            'invitations.revoke' => $version,
            'document-grants.store' => ['documentVersionIds' => ['required', 'array', 'min:1', 'max:20'], 'documentVersionIds.*' => ['required', 'integer', 'min:1', 'distinct']],
            'document-grants.revoke' => [],
            default => [],
        };
    }

    /** @return array<string, list<mixed>> */
    private function selection(): array
    {
        return ['contextSnapshotIds' => ['present', 'array', 'max:20'], 'contextSnapshotIds.*' => ['integer', 'min:1', 'distinct'],
            'documentIds' => ['present', 'array', 'max:20'], 'documentIds.*' => ['integer', 'min:1', 'distinct'],
            'followUpKeys' => ['present', 'array', 'max:8'], 'followUpKeys.*' => ['string', 'max:60', 'distinct']];
    }
}
