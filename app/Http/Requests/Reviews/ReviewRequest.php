<?php

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

/** User-realm review submit/edit. Ownership is enforced by the controller lookups (foreign objects are 404). */
class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [];
        }
        $rules = ['rating' => ['required', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:'.config('reviews.max_body_length')]];
        if ($this->isMethod('PUT')) {
            $rules['expectedVersion'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }
}
