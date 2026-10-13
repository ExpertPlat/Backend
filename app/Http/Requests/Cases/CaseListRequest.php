<?php

namespace App\Http\Requests\Cases;

use App\Enums\CaseStatus;
use App\Models\Admin;
use App\Services\Cases\IntakeSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CaseListRequest extends CaseRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('admin.cases.*')) {
            if (! $this->user() instanceof Admin) {
                return false;
            } Gate::authorize('cases.viewAny');

            return true;
        }

        return parent::authorize();
    }

    public function rules(): array
    {
        $rules = ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'], 'sortDirection' => ['sometimes', Rule::in(['asc', 'desc'])]];
        if ($this->routeIs('user.cases.index', 'admin.cases.index')) {
            $rules += ['sortBy' => ['sometimes', Rule::in(['updatedAt', 'createdAt', 'submittedAt'])],
                'status' => ['sometimes', Rule::enum(CaseStatus::class)], 'domain' => ['sometimes', Rule::in(app(IntakeSchema::class)->domains())],
                'jurisdiction' => ['sometimes', Rule::in(config('countries'))], 'language' => ['sometimes', Rule::in(config('case_intake.languages'))],
                'urgency' => ['sometimes', Rule::in(['normal', 'soon', 'urgent'])],
                'userId' => [$this->routeIs('admin.cases.index') ? 'sometimes' : 'prohibited', 'integer', 'min:1']];
        }

        return $rules;
    }
}
