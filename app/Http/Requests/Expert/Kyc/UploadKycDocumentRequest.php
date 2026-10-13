<?php

namespace App\Http\Requests\Expert\Kyc;

use App\Enums\ExpertKycDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UploadKycDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'documentType' => ['required', Rule::enum(ExpertKycDocumentType::class)],
            'qualificationId' => ['nullable', 'integer', 'min:1'],
            'credentialId' => ['nullable', 'integer', 'min:1'],
            'file' => [
                'required',
                File::types(config('kyc.documents.allowed_extensions'))
                    ->max((int) config('kyc.documents.max_size_kilobytes')),
            ],
        ];
    }
}
