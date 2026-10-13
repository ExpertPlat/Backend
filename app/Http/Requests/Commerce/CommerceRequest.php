<?php

namespace App\Http\Requests\Commerce;

use App\Http\Requests\Cases\CaseRequest;
use App\Services\Commerce\Agreements;
use App\Services\Commerce\Proposals;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** User-realm Sprint 4 requests. Ownership/policy checks come from CaseRequest (case route parameter). Unknown fields are rejected. */
class CommerceRequest extends CaseRequest
{
    private const START_AT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/D';

    public function rules(): array
    {
        $name = (string) $this->route()->getName();
        if ($this->isMethod('GET')) {
            if (str_ends_with($name, 'availability')) {
                return ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']];
            }

            return ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'status' => ['sometimes', 'string', Rule::in(['submitted', 'change_requested', 'accepted', 'rejected', 'withdrawn', 'expired', 'awaiting_payment', 'cancelled', 'held', 'confirmed'])]];
        }
        $version = ['expectedVersion' => ['required', 'integer', 'min:1']];
        $startAt = ['startAt' => ['required', 'string', 'regex:'.self::START_AT]];

        return match (true) {
            str_ends_with($name, 'change-requests.store') => $version + ['versionNo' => ['required', 'integer', 'min:1'], 'categories' => ['required', 'array', 'min:1', 'max:5'],
                'categories.*' => ['required', Rule::in(Proposals::CHANGE_CATEGORIES), 'distinct'], 'message' => ['required', 'string', 'min:3', 'max:2000']],
            str_ends_with($name, 'proposals.accept') => $version + ['versionNo' => ['required', 'integer', 'min:1'], 'totalAmountMinor' => ['required', 'integer', 'min:1'],
                'currency' => ['required', 'string', 'size:3'], 'acknowledge' => ['required', 'accepted']],
            str_ends_with($name, 'proposals.reject') => $version + ['reasonCode' => ['sometimes', 'nullable', Rule::in(Proposals::REJECT_REASONS)]],
            str_ends_with($name, 'agreements.cancel'), str_ends_with($name, 'bookings.cancel') => $version + ['reasonCode' => ['required', Rule::in(Agreements::CANCEL_REASONS)]],
            str_ends_with($name, 'bookings.store') => $startAt,
            str_ends_with($name, 'bookings.reschedule') => $version + $startAt,
            str_ends_with($name, 'bookings.confirm') => $version,
            default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $validator->errors()->add($key, 'This field is not allowed.');
            }
            if ($this->has('totalAmountMinor') && ! is_int($this->input('totalAmountMinor'))) {
                $validator->errors()->add('totalAmountMinor', 'The amount must be an integer in minor units.');
            }
            if ($this->isMethod('GET') && $this->filled('from') && $this->filled('to') && ! $validator->errors()->hasAny(['from', 'to'])) {
                $from = CarbonImmutable::parse($this->input('from'));
                if ($from->lt(now()->utc()->startOfDay())) {
                    $validator->errors()->add('from', 'The start date cannot be in the past.');
                }
                if ($from->diffInDays(CarbonImmutable::parse($this->input('to'))) > (int) config('commerce.availability_max_days')) {
                    $validator->errors()->add('to', 'The range is too long.');
                }
            }
        }];
    }
}
