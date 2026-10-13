<?php

namespace App\Http\Requests\Cases;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CaseUploadRequest extends CaseRequest
{
    public function rules(): array
    {
        return ['expectedVersion' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'min:1', 'max:160'],
            'category' => ['required', Rule::in($this->categories())],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'extensions:pdf,jpg,jpeg,png', 'max:'.config('case_intake.max_file_kib')]];
    }

    private function categories(): array
    {
        $case = $this->user()->cases()->findOrFail($this->route('case'));
        $required = $case->serviceScopes()->whereNull('detached_at')->with('catalogVersion')->get()->flatMap(fn ($s) => $s->catalogVersion->policy['requiredDocuments'])->all();

        return array_values(array_unique(array_merge(['supporting', 'reference', 'work_sample'], $required)));
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            $file = $this->file('file');
            if (! $file || ! $file->isValid()) {
                return;
            }
            $allowed = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']];
            if (! in_array(strtolower($file->getClientOriginalExtension()), $allowed[(new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath())] ?? [], true)) {
                $validator->errors()->add('file', 'File type and extension must match.');
            }
        }];
    }
}
