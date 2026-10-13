<?php

namespace App\Http\Requests\Expert\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpertProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'professionalTitle' => ['required', 'string', 'min:3', 'max:160'],
            'bio' => ['required', 'string', 'min:80', 'max:2000'],
            'yearsExperience' => ['nullable', 'integer', 'min:0', 'max:80'],
            'specialties' => ['sometimes', 'array', 'max:12'],
            'specialties.*' => ['required', 'string', 'min:2', 'max:80', 'distinct:strict'],
            'publicLanguages' => ['required', 'array', 'min:1', 'max:5'],
            'publicLanguages.*' => ['required', 'string', Rule::in(['ar', 'en']), 'distinct:strict'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['professionalTitle', 'bio'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }

        foreach (['specialties', 'publicLanguages'] as $field) {
            if (! is_array($this->input($field))) {
                continue;
            }

            $values = array_values(array_unique(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $this->input($field),
            ))));

            $this->merge([$field => $values]);
        }
    }
}
