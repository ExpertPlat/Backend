<?php

namespace App\Http\Requests\Payments;

use App\Models\Expert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Expert-realm Sprint 5 requests: none accept a body. Anything sent is rejected. */
class ExpertPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Expert;
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->all()) as $key) {
                $validator->errors()->add((string) $key, 'This field is not allowed.');
            }
        }];
    }
}
