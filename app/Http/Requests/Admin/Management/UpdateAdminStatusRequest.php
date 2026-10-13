<?php

namespace App\Http\Requests\Admin\Management;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateAdminStatusRequest extends FormRequest
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
        return ['is_active' => ['required', 'boolean']];
    }
}
