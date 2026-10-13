<?php

namespace App\Http\Requests\Reviews;

use App\Models\ExpertReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $states = array_keys(ExpertReview::TRANSITIONS);
        if ($this->isMethod('GET')) {
            return ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'status' => ['sometimes', Rule::in($states)], 'expertId' => ['sometimes', 'integer', 'min:1']];
        }

        return ['status' => ['required', Rule::in($states)], 'expectedVersion' => ['required', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:1000']];
    }
}
