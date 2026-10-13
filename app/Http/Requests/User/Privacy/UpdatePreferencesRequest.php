<?php

namespace App\Http\Requests\User\Privacy;

use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends PrivacyRequest
{
    public function rules(): array
    {
        return [
            'expectedVersion' => ['required', 'integer', 'min:0'],
            'contactChannels' => ['sometimes', 'array', 'max:1'],
            'contactChannels.*' => ['required', Rule::in(['email']), 'distinct:strict'],
            'aiAssistanceEnabled' => ['sometimes', 'boolean'],
            'recordingPreference' => ['sometimes', 'boolean'],
            'contextVisibility' => ['sometimes', Rule::in(['private'])],

        ];
    }
}
