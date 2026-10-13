<?php

namespace App\Http\Requests\Admin\Kyc;

use App\Enums\ExpertKycReviewSection;
use App\Models\ExpertKycApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestKycInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var ExpertKycApplication|null $application */
        $application = $this->route('application');

        return [
            'reason' => ['required', 'string', 'min:3', 'max:5000'],
            'requestedChanges' => ['required', 'array', 'min:1', 'max:20'],
            'requestedChanges.*.section' => ['required', Rule::enum(ExpertKycReviewSection::class)],
            'requestedChanges.*.field' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[A-Za-z][A-Za-z0-9_.-]{0,99}$/',
            ],
            'requestedChanges.*.documentId' => [
                'nullable',
                'integer',
                Rule::exists('expert_kyc_documents', 'id')
                    ->where('application_id', $application?->getKey() ?? 0),
            ],
            'requestedChanges.*.message' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $this->merge(['reason' => trim((string) $this->input('reason'))]);
        }

        if (! is_array($this->input('requestedChanges'))) {
            return;
        }

        $changes = array_map(static function ($change): mixed {
            if (! is_array($change)) {
                return $change;
            }

            foreach (['section', 'field', 'message'] as $field) {
                if (array_key_exists($field, $change) && is_string($change[$field])) {
                    $change[$field] = trim($change[$field]);
                }
            }

            return $change;
        }, $this->input('requestedChanges'));

        $this->merge(['requestedChanges' => $changes]);
    }
}
