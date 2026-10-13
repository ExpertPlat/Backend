<?php

namespace App\Http\Requests\Admin\Management;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Role;

class StoreAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('create', Admin::class);

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('admins', 'email')],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('guard_name', 'admin'),
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('role_id')) {
                return;
            }

            $role = Role::query()
                ->where('guard_name', 'admin')
                ->find($this->integer('role_id'));

            if ($role?->name === AdminRole::SuperAdmin->value) {
                $validator->errors()->add(
                    'role_id',
                    'The super administrator role cannot be assigned through the sub-administrator invitation flow.',
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
