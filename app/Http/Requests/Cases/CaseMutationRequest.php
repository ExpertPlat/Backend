<?php

namespace App\Http\Requests\Cases;

class CaseMutationRequest extends CaseRequest
{
    public function rules(): array
    {
        $rules = ['expectedVersion' => ['required', 'integer', 'min:1']];
        if ($this->routeIs('user.cases.context-snapshots.store')) {
            $rules += ['contextId' => ['required', 'integer', 'min:1'], 'contextVersion' => ['required', 'integer', 'min:1'],
                'selectedFactKeys' => ['required', 'array', 'min:1', 'max:20'], 'selectedFactKeys.*' => ['required', 'string', 'max:80', 'distinct:strict'],
                'authorizeUse' => ['required', 'accepted']];
        }
        if ($this->routeIs('user.cases.intake-confirmation.store')) {
            $rules += ['assessmentId' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']];
        }

        return $rules;
    }
}
