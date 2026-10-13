<?php

namespace App\Http\Requests\Admin\Management;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Admin $admin */
        $admin = $this->route('admin');
        Gate::authorize('update', $admin);

        return true;
    }

    public function rules(): array
    {
        /** @var Admin $admin */
        $admin = $this->route('admin');

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('admins', 'email')->ignore($admin),
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->hasAny(['name', 'email'])) {
                $validator->errors()->add(
                    'admin',
                    'At least one administrator field must be provided.',
                );
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        if ($this->has('email')) {
            $this->merge([
                'email' => mb_strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }
}
