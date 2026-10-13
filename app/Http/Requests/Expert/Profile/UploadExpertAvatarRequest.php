<?php

namespace App\Http\Requests\Expert\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UploadExpertAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:min_width=256,min_height=256,max_width=5000,max_height=5000',
            ],
        ];
    }
}
