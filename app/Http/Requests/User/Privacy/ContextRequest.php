<?php

namespace App\Http\Requests\User\Privacy;

use Illuminate\Validation\Rule;

class ContextRequest extends PrivacyRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $domain = $creating ? $this->input('domain') : $this->user()?->contexts()->whereKey($this->route('context'))->value('domain');
        // Authorization/ownership is resolved before field-level feedback for existing records.
        $keys = is_string($domain) ? (config('context_schemas.domains', [])[$domain] ?? []) : [];
        $presence = $creating ? 'required' : 'sometimes';

        return [
            'domain' => [$creating ? 'required' : 'prohibited', Rule::in(array_keys(config('context_schemas.domains')))],
            'country' => [$creating ? 'required' : 'prohibited', Rule::in(config('countries'))],
            'schemaVersion' => [$creating ? 'required' : 'prohibited', 'integer', Rule::in([1])],
            'expectedVersion' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'title' => [$presence, 'required', 'string', 'min:1', 'max:160'],
            'facts' => [$presence, 'array', 'min:1', 'max:20'],
            'facts.*' => ['required', 'array:key,value,source,effectiveDate'],
            'facts.*.key' => ['required', 'string', Rule::in($keys), 'distinct:strict'],
            'facts.*.value' => ['required', 'string', 'min:1', 'max:2000'],
            'facts.*.source' => ['required', 'string', 'min:1', 'max:120'],
            'facts.*.effectiveDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'allowCaseReuse' => ['sometimes', 'boolean'],
            'clarification' => $creating ? ['prohibited'] : ['sometimes', 'required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        if (! $this->isMethod('POST')) {
            abort_unless($this->user()->contexts()->whereKey($this->route('context'))->where('status', '!=', 'deleted')->exists(), 404);
        }

        return true;
    }
}
