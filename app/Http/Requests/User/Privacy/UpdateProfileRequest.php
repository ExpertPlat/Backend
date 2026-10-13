<?php

namespace App\Http\Requests\User\Privacy;

use Illuminate\Validation\Rule;

class UpdateProfileRequest extends PrivacyRequest
{
    public function rules(): array
    {
        return [
            'expectedVersion' => ['required', 'integer', 'min:0'],
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'country' => ['sometimes', 'nullable', Rule::in(config('countries'))],
            'language' => ['sometimes', 'nullable', Rule::in(config('privacy.languages'))],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64', 'timezone:all'],

        ];
    }
}
