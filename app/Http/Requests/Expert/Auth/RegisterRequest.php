<?php

namespace App\Http\Requests\Expert\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('name')) {
            $data['name'] = trim((string) $this->input('name'));
        }

        if ($this->has('email')) {
            $data['email'] = mb_strtolower(trim((string) $this->input('email')));
        }

        if ($this->has('device_name')) {
            $data['device_name'] = trim((string) $this->input('device_name'));
        }

        $this->merge($data);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:experts,email'],
            'country' => ['sometimes', 'nullable', 'string', 'max:16'],
            'language' => ['sometimes', 'nullable', 'string', 'max:16'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:50'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
