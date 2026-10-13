<?php

namespace App\Http\Requests\User\Privacy;

use App\Enums\DataRequestStatus;
use App\Models\Admin;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ListPrivacyRequest extends PrivacyRequest
{
    public function authorize(): bool
    {
        if ($this->is('api/admin/*')) {
            if (! $this->user() instanceof Admin) {
                return false;
            }
            Gate::authorize($this->routeIs('admin.users.consents.index') ? 'users.consentMetadata.view' : 'dataRequests.viewAny');

            return true;
        }

        if (! parent::authorize()) {
            return false;
        }
        if ($this->route('context')) {
            abort_unless($this->user()->contexts()->whereKey($this->route('context'))->where('status', '!=', 'deleted')->exists(), 404);
        }

        return true;
    }

    public function rules(): array
    {
        $rules = [
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sortDirection' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
        if ($this->routeIs('user.contexts.index')) {
            return $rules + [
                'sortBy' => ['sometimes', Rule::in(['updatedAt', 'createdAt'])],
                'status' => ['sometimes', Rule::in(['active', 'archived'])],
                'domain' => ['sometimes', Rule::in(array_keys(config('context_schemas.domains')))],
                'country' => ['sometimes', Rule::in(config('countries'))],
            ];
        }
        if ($this->routeIs('user.contexts.versions.index')) {
            return $rules + ['sortBy' => ['sometimes', Rule::in(['version'])]];
        }
        if ($this->routeIs('user.consents.history', 'admin.users.consents.index')) {
            return $rules + [
                'sortBy' => ['sometimes', Rule::in(['decidedAt'])],
                'purpose' => ['sometimes', Rule::in(config('privacy.purposes'))],
                'policyVersionId' => ['sometimes', 'integer', 'min:1'],
            ];
        }

        return $rules + [
            'sortBy' => ['sometimes', Rule::in(['requestedAt', 'dueAt'])],
            'type' => ['sometimes', Rule::in(['export', 'deletion'])],
            'status' => ['sometimes', Rule::enum(DataRequestStatus::class)],
            'userId' => [$this->is('api/admin/*') ? 'sometimes' : 'prohibited', 'integer', 'min:1'],
        ];
    }
}
