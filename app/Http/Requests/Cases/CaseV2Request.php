<?php

namespace App\Http\Requests\Cases;

use App\Enums\CaseReadinessStatus;
use App\Models\User;
use Illuminate\Validation\Rule;

class CaseV2Request extends CaseRequest
{
    public function owner(): User
    {
        $actor = $this->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'], 'status' => ['sometimes', Rule::enum(CaseReadinessStatus::class)], 'sortBy' => ['sometimes', Rule::in(['updatedAt', 'createdAt'])], 'sortDirection' => ['sometimes', Rule::in(['asc', 'desc'])]];
        }
        $action = last(explode('.', $this->route()->getName()));
        $version = ['expectedVersion' => ['required', 'integer', 'min:1']];
        if (in_array($action, ['assessment', 'confirm', 'cancel'])) {
            return $version + ($action === 'confirm' ? ['confirmed' => ['required', 'accepted']] : []);
        }
        if ($action === 'submit') {
            return $version + ['selectedScopeIds' => ['sometimes', 'array', 'min:1', 'max:20'], 'selectedScopeIds.*' => ['integer', 'min:1', 'distinct'], 'partialConsent' => ['sometimes', 'boolean']];
        }
        $scopes = ['scopes' => ['required', 'array', 'min:1', 'max:20'], 'scopes.*' => ['array:catalogEntryId,catalogVersion,deliveryMode,jurisdictionCodes,answers,confirmed'],
            'scopes.*.catalogEntryId' => ['required', 'integer', 'min:1'], 'scopes.*.catalogVersion' => ['required', 'integer', 'min:1'], 'scopes.*.deliveryMode' => ['required', 'string', 'max:100'],
            'scopes.*.jurisdictionCodes' => ['present', 'array', 'max:20'], 'scopes.*.jurisdictionCodes.*' => ['string', 'max:100'],
            'scopes.*.answers' => ['present', 'array', 'max:50'], 'scopes.*.confirmed' => ['required', 'boolean']];
        if ($action === 'scopes') {
            return $version + $scopes;
        }

        return ($action === 'update' ? $version : []) + [
            'title' => ['sometimes', 'required', 'string', 'max:160'], 'problemDescription' => ['sometimes', 'required', 'string', 'max:10000'], 'desiredOutcome' => ['sometimes', 'required', 'string', 'max:4000'],
            'caseCountry' => ['sometimes', 'nullable', 'string', 'size:2', Rule::exists('catalog_nodes', 'code')->where('kind', 'country')],
            'language' => ['sometimes', 'required', Rule::in(['ar', 'en'])], 'urgency' => ['sometimes', 'required', Rule::in(['normal', 'soon', 'urgent'])],
            'subjectType' => ['sometimes', Rule::in(['self'])], 'privacyChoice' => ['sometimes', Rule::in(['private'])],
            'answers' => ['sometimes', 'array:immediateDanger,requiresInPerson,ambiguousHighRisk'],
            'answers.immediateDanger' => ['sometimes', 'boolean'], 'answers.requiresInPerson' => ['sometimes', 'boolean'], 'answers.ambiguousHighRisk' => ['sometimes', 'boolean'],
            'lastCompletedStep' => ['sometimes', Rule::in(['description', 'context', 'details', 'documents', 'review'])],
        ];
    }
}
