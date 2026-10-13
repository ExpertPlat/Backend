<?php

namespace App\Http\Requests\User\Privacy;

use App\Enums\ConsentDecision;
use Illuminate\Validation\Rule;

class ConsentDecisionRequest extends PrivacyRequest
{
    public function rules(): array
    {
        return [
            'purpose' => ['required', Rule::in(config('privacy.purposes'))],
            'policyVersionId' => ['required', 'integer', 'min:1'],
            'decision' => ['required', Rule::enum(ConsentDecision::class)],
            'previousRecordId' => ['required_if:decision,withdrawn', 'prohibited_unless:decision,withdrawn', 'integer', 'min:1'],

        ];
    }
}
