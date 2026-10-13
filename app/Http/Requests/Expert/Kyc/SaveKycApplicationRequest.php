<?php

namespace App\Http\Requests\Expert\Kyc;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveKycApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fullName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'max:16'],
            'language' => ['sometimes', 'nullable', 'string', 'max:16'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:50'],
            'jurisdiction' => ['sometimes', 'nullable', 'string', 'max:255'],
            'experiences' => ['sometimes', 'array', 'max:20'],
            'experiences.*.id' => ['sometimes', 'integer', 'min:1'],
            'experiences.*.jobTitle' => ['required', 'string', 'max:255'],
            'experiences.*.organization' => ['required', 'string', 'max:255'],
            'experiences.*.from' => ['nullable', 'date_format:Y-m'],
            'experiences.*.to' => ['nullable', 'date_format:Y-m', 'after_or_equal:experiences.*.from'],
            'experiences.*.current' => ['sometimes', 'boolean'],
            'experiences.*.description' => ['nullable', 'string', 'max:5000'],
            'qualifications' => ['sometimes', 'array', 'max:20'],
            'qualifications.*.id' => ['sometimes', 'integer', 'min:1'],
            'qualifications.*.degree' => ['required', 'string', 'max:255'],
            'qualifications.*.field' => ['nullable', 'string', 'max:255'],
            'qualifications.*.institution' => ['required', 'string', 'max:255'],
            'qualifications.*.graduationYear' => ['nullable', 'integer', 'min:1900', 'max:'.(now()->year + 10)],
            'credentials' => ['sometimes', 'array', 'max:20'],
            'credentials.*.id' => ['sometimes', 'integer', 'min:1'],
            'credentials.*.type' => ['required', Rule::in(['certificate', 'license'])],
            'credentials.*.name' => ['required', 'string', 'max:255'],
            'credentials.*.issuer' => ['required', 'string', 'max:255'],
            'credentials.*.issueDate' => ['nullable', 'date'],
            'credentials.*.expiryDate' => ['nullable', 'date', 'after_or_equal:credentials.*.issueDate'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['fullName', 'country', 'language', 'domain', 'jurisdiction'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }
}
