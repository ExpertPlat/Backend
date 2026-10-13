<?php

namespace App\Http\Requests\Catalog;

use App\Enums\CatalogStatus;
use App\Http\Requests\User\Privacy\PrivacyRequest;
use App\Models\Admin;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CatalogRequest extends PrivacyRequest
{
    public function admin(): Admin
    {
        $actor = $this->user();
        abort_unless($actor instanceof Admin, 403);

        return $actor;
    }

    public function authorize(): bool
    {
        if (! $this->routeIs('admin.catalog.*')) {
            return true;
        }
        if (! $this->user() instanceof Admin) {
            return false;
        }
        $action = last(explode('.', $this->route()->getName()));
        $permission = match ($action) {
            'impact','waiting' => 'viewImpact','review','grant','revoke' => 'review','publish' => 'publish','pause' => 'pause','store','node','version','updateNode' => 'manageDrafts',default => 'view'
        };
        Gate::authorize('catalog.'.$permission);
        if (in_array($action, ['grant', 'revoke'])) {
            Gate::authorize('experts.reviewKyc');
        }

        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'], 'kind' => ['sometimes', Rule::in(['country', 'jurisdiction', 'domain', 'specialty', 'service', 'delivery_mode', 'safety_rule'])], 'parentId' => ['sometimes', 'integer', 'min:1'], 'status' => ['sometimes', Rule::enum(CatalogStatus::class)], 'code' => ['sometimes', 'string', 'max:100'], 'domain' => ['sometimes', 'string', 'max:100'], 'sortBy' => ['sometimes', Rule::in(['id', 'updatedAt'])], 'sortDirection' => ['sometimes', Rule::in(['asc', 'desc'])]];
        }
        $action = last(explode('.', $this->route()->getName()));

        return match ($action) {
            'node' => ['kind' => ['required', Rule::in(['country', 'jurisdiction', 'domain', 'specialty', 'service', 'delivery_mode', 'safety_rule'])], 'code' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_-]{0,99}$/D'], 'parentId' => ['nullable', 'integer', 'min:1'], 'regulated' => ['sometimes', 'nullable', 'boolean'], 'rules' => ['required_if:kind,safety_rule', 'array:keywords,reasonCode'], 'rules.keywords' => ['required_with:rules', 'array', 'min:1', 'max:100'], 'rules.keywords.*' => ['required', 'string', 'min:2', 'max:100'], 'rules.reasonCode' => ['required_with:rules', Rule::in(['SAFETY_OR_EMERGENCY', 'REMOTE_DELIVERY_NOT_ALLOWED', 'HUMAN_TRIAGE_REQUIRED'])], 'labels' => ['required', 'array:ar,en'], 'labels.ar' => ['required', 'string', 'max:200'], 'labels.en' => ['required', 'string', 'max:200']],
            'updateNode' => ['expectedVersion' => ['required', 'integer', 'min:1'], 'labels' => ['sometimes', 'array:ar,en'], 'labels.ar' => ['required_with:labels', 'string', 'max:200'], 'labels.en' => ['required_with:labels', 'string', 'max:200'], 'status' => ['sometimes', Rule::in(['draft', 'enabled', 'paused', 'blocked', 'retired'])]],
            'store' => ['code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,99}$/D'], 'policy' => ['required', 'array']],
            'version' => ['expectedVersion' => ['required', 'integer', 'min:1'], 'policy' => ['required', 'array']],
            'review' => ['expectedVersion' => ['required', 'integer', 'min:1'], 'approved' => ['required', 'accepted'], 'reasonCode' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/D']],
            'publish' => ['expectedVersion' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['intake_only', 'pilot', 'enabled'])], 'impactToken' => ['required', 'string', 'size:64']],
            'pause' => ['expectedVersion' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['paused', 'retired', 'blocked'])], 'impactToken' => ['required', 'string', 'size:64'], 'reasonCode' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/D']],
            'grant' => ['scopeId' => ['required', 'integer', 'min:1'], 'catalogVersionId' => ['required', 'integer', 'min:1'], 'jurisdictionCode' => ['required', 'string', 'max:100'], 'evidenceId' => ['required', 'integer', 'min:1'], 'reasonCode' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/D']],
            'revoke' => ['reasonCode' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/D']], default => []
        };
    }
}
