<?php

namespace App\Http\Requests\Admin\Kyc;

use App\Enums\ExpertKycApplicationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListKycApplicationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::in(ExpertKycApplicationStatus::adminVisibleValues())],
            'dateFrom' => ['sometimes', 'nullable', 'date'],
            'dateTo' => ['sometimes', 'nullable', 'date', 'after_or_equal:dateFrom'],
            'sortBy' => ['sometimes', Rule::in(['submittedAt', 'updatedAt', 'reference'])],
            'sortDirection' => ['sometimes', Rule::in(['asc', 'desc'])],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
