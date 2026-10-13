<?php

namespace App\Http\Requests\User\Privacy;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class PrivacyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $validator->errors()->add($key, 'This field is not allowed.');
            }
            if ($this->isMethod('PATCH') && count(array_diff(array_keys($this->all()), ['expectedVersion'])) === 0) {
                $validator->errors()->add('payload', 'At least one mutable field is required.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone', 'title', 'clarification'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper(trim($this->input('country')))]);
        }
    }
}
