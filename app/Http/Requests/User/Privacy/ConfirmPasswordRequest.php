<?php

namespace App\Http\Requests\User\Privacy;

use Illuminate\Validation\Rule;

class ConfirmPasswordRequest extends PrivacyRequest
{
    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string', 'max:4096'],
            'purpose' => ['required', Rule::in(['export', 'deletion'])],

        ];
    }
}
