<?php

namespace App\Http\Requests\Expert\Renewal;

use App\Enums\ExpertRenewalStatus;
use App\Models\Expert;
use App\Models\ExpertScopeRenewal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class RenewalRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() instanceof Expert) {
            if ($this->route('renewal')) {
                $row = ExpertScopeRenewal::query()->where('expert_id', $this->user()->id)->findOrFail($this->route('renewal'));
                Gate::authorize($this->isMethod('GET') ? 'view' : 'update', $row);
            }
            if ($this->route('scope')) {
                $this->user()->verifiedScopes()->findOrFail($this->route('scope'));
            }
        } else {
            Gate::authorize($this->permission());
        }

        return true;
    }

    public function permission(): string
    {
        return 'expertRenewals.'.match ($this->route()->getActionMethod()) {
            'index' => 'viewAny','show' => 'view','document' => 'viewEvidence',default => 'review'
        };
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        if ($action === 'index' || $action === 'scopes') {
            $rules = ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'between:1,100']];

            return $action === 'scopes' ? $rules : $rules + ['status' => ['sometimes', Rule::enum(ExpertRenewalStatus::class)], 'scopeId' => ['sometimes', 'integer', 'min:1'], 'sort' => ['sometimes', Rule::in(['newest', 'oldest'])]];
        }
        if (in_array($action, ['show', 'document', 'store'])) {
            return [];
        }
        $rules = ['version' => ['required', 'integer', 'min:1']];
        if (in_array($action, ['reject', 'requestInformation'])) {
            return $rules + ['reason' => ['required', 'string', 'min:10', 'max:2000']];
        }
        if ($action === 'approve') {
            return $rules + [
                'evidenceReviewed' => ['required', 'boolean', 'accepted'],
                'validUntil' => ['required', 'date_format:Y-m-d', 'after:today'],
                'nextReviewAt' => ['required', 'date_format:Y-m-d', 'after:today'],
                'professionalReview' => ['sometimes', 'array:verifiedCountry,regulator,registrationNumber,verificationSource,statusChecked'],
                'professionalReview.verifiedCountry' => ['required_with:professionalReview', 'string', 'size:2'],
                'professionalReview.regulator' => ['required_with:professionalReview', 'string', 'max:255'],
                'professionalReview.registrationNumber' => ['required_with:professionalReview', 'string', 'max:100'],
                'professionalReview.verificationSource' => ['required_with:professionalReview', 'url:https', 'max:2048'],
                'professionalReview.statusChecked' => ['required_with:professionalReview', Rule::in(['active'])],
            ];
        }
        if ($action !== 'evidence') {
            return $rules;
        }
        $fields = match ($this->input('evidence.type')) {
            'credential' => ['credentialType' => ['required', Rule::in(['license', 'certificate'])], 'name' => ['required', 'string', 'max:255'], 'issuer' => ['required', 'string', 'max:255'], 'issueDate' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'expiryDate' => ['nullable', 'date_format:Y-m-d', 'after:today']],
            'qualification' => ['degree' => ['required', 'string', 'max:255'], 'institution' => ['required', 'string', 'max:255'], 'field' => ['nullable', 'string', 'max:255'], 'graduationYear' => ['nullable', 'integer', 'between:1900,'.today()->year]],
            'experience' => ['jobTitle' => ['required', 'string', 'max:255'], 'organization' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:2000']],
            default => [],
        };
        $rules += ['file' => ['required', File::types(config('kyc.documents.allowed_extensions'))->max(config('kyc.documents.max_size_kilobytes'))],
            'evidence' => ['required', 'array:type,note,'.implode(',', array_keys($fields))],
            'evidence.type' => ['required', Rule::in(['credential', 'qualification', 'experience'])], 'evidence.note' => ['nullable', 'string', 'max:2000']];
        foreach ($fields as $key => $validation) {
            $rules['evidence.'.$key] = $validation;
        }

        return $rules;
    }

    public function after(): array
    {
        return [function ($validator): void {
            $allowed = array_unique(array_map(fn ($key) => explode('.', $key)[0], array_keys($this->rules())));
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $validator->errors()->add($key, 'This field is not allowed.');
            }
            if ($this->input('evidence.issueDate') && $this->input('evidence.expiryDate') && $this->input('evidence.expiryDate') < $this->input('evidence.issueDate')) {
                $validator->errors()->add('evidence.expiryDate', 'Expiry must follow issue date.');
            }
        }];
    }
}
