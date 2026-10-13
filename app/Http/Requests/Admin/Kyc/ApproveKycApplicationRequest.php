<?php

namespace App\Http\Requests\Admin\Kyc;

use App\Enums\AdminPermission;
use App\Models\ExpertKycApplication;
use App\Services\Catalog\CatalogTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ApproveKycApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize(AdminPermission::ExpertsReviewKyc->value);

        return true;
    }

    public function rules(): array
    {
        return [
            'scopes' => ['required', 'array', 'min:1', 'max:5'],
            'scopes.*.catalogPolicyVersionId' => ['sometimes', 'integer', 'min:1'],
            'scopes.*.domain' => ['required', 'string', 'max:50'],
            'scopes.*.jurisdiction' => ['required', 'string', 'max:255'],
            'scopes.*.jurisdictionCountry' => ['sometimes', 'string', 'size:2', 'alpha'],
            'scopes.*.role' => ['required', 'string', 'min:2', 'max:100'],
            'scopes.*.serviceTypes' => ['required', 'array', 'min:1', 'max:5'],
            'scopes.*.serviceTypes.*' => ['required', Rule::in(CatalogTaxonomy::deliveryModes()), 'distinct:strict'],
            'scopes.*.languages' => ['required', 'array', 'min:1', 'max:5'],
            'scopes.*.languages.*' => ['required', Rule::in(['ar', 'en']), 'distinct:strict'],
            'scopes.*.validUntil' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'scopes.*.evidence' => ['required', 'array'],
            'scopes.*.evidence.type' => ['required', Rule::in(['credential', 'qualification', 'experience'])],
            'scopes.*.evidence.id' => ['required', 'integer', 'min:1'],
            'scopes.*.professionalReview' => ['sometimes', 'array'],
            'scopes.*.professionalReview.verifiedCountry' => ['sometimes', 'string', 'max:16'],
            'scopes.*.professionalReview.regulator' => ['sometimes', 'string', 'max:255'],
            'scopes.*.professionalReview.registrationNumber' => ['sometimes', 'string', 'max:100'],
            'scopes.*.professionalReview.verificationSource' => ['sometimes', 'url:http,https', 'max:2048'],
            'scopes.*.professionalReview.statusChecked' => ['sometimes', Rule::in(['active'])],
            'scopes.*.professionalReview.nextReviewAt' => ['sometimes', 'date_format:Y-m-d', 'after:today'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var ExpertKycApplication|null $application */
            $application = $this->route('application');

            if (! $application || ! is_array($this->input('scopes'))) {
                return;
            }

            $seen = [];
            foreach ($this->input('scopes') as $index => $scope) {
                if (($scope['domain'] ?? null) !== $application->domain) {
                    $validator->errors()->add("scopes.{$index}.domain", 'The scope domain must match the reviewed KYC application.');
                }

                if (($scope['jurisdiction'] ?? null) !== $application->jurisdiction) {
                    $validator->errors()->add("scopes.{$index}.jurisdiction", 'The scope jurisdiction must match the reviewed KYC application.');
                }

                $key = mb_strtolower(implode('|', [
                    (string) ($scope['domain'] ?? ''),
                    (string) ($scope['jurisdiction'] ?? ''),
                    (string) ($scope['role'] ?? ''),
                ]));

                if (isset($seen[$key])) {
                    $validator->errors()->add("scopes.{$index}.role", 'Duplicate verification scopes are not allowed.');
                }

                $seen[$key] = true;
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('scopes'))) {
            return;
        }

        $scopes = array_map(static function ($scope): mixed {
            if (! is_array($scope)) {
                return $scope;
            }

            foreach (['domain', 'jurisdiction', 'role'] as $field) {
                if (isset($scope[$field]) && is_string($scope[$field])) {
                    $scope[$field] = trim($scope[$field]);
                }
            }

            return $scope;
        }, $this->input('scopes'));

        $this->merge(['scopes' => $scopes]);
    }
}
