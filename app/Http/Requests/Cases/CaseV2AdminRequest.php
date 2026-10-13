<?php

namespace App\Http\Requests\Cases;

use App\Models\Admin;
use Illuminate\Support\Facades\Gate;

class CaseV2AdminRequest extends CaseV2Request
{
    public function authorize(): bool
    {
        if (! $this->user() instanceof Admin) {
            return false;
        }Gate::authorize($this->route('case') ? 'cases.view' : 'cases.viewAny');

        return true;
    }

    public function rules(): array
    {
        return parent::rules() + ['userId' => ['sometimes', 'integer', 'min:1']];
    }
}
