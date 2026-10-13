<?php

namespace App\Http\Requests\User\Privacy;

use App\Enums\DataRequestType;
use Illuminate\Validation\Rule;

class StoreDataRequest extends PrivacyRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DataRequestType::class)],
            'scope' => ['required', Rule::in(['account'])],

        ];
    }
}
