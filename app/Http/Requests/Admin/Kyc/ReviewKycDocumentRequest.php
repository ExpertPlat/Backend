<?php

namespace App\Http\Requests\Admin\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class ReviewKycDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reviewed' => ['required', 'boolean']];
    }
}
