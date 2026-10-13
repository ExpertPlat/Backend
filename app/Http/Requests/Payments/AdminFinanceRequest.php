<?php

namespace App\Http\Requests\Payments;

use App\Models\Admin;
use App\Models\FundingRefund;
use App\Services\Payments\Reconciliation;
use App\Services\Payments\Refunds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Admin finance requests. Authorization is by explicit permission in the controller (Gate::authorize); unknown fields are rejected. */
class AdminFinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Admin;
    }

    public function rules(): array
    {
        $name = (string) $this->route()->getName();
        if ($this->isMethod('GET')) {
            return ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'], 'status' => ['sometimes', 'string', 'max:30'],
                'fundingId' => ['sometimes', 'integer', 'min:1'], 'kind' => ['sometimes', Rule::in(Reconciliation::KINDS)]];
        }
        if (str_ends_with($name, 'refunds.decision')) {
            return ['decision' => ['required', Rule::in(['approve_full', 'approve_partial', 'reject'])], 'amountMinor' => ['required_if:decision,approve_partial', 'nullable', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'min:5', 'max:1000'], 'expectedVersion' => ['required', 'integer', 'min:1']];
        }
        if (str_ends_with($name, 'fundings.refunds.store')) {
            return ['reasonCode' => ['required', Rule::in(Refunds::ADMIN_REASONS)], 'amountMinor' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'attemptId' => ['required_if:reasonCode,LATE_PAYMENT,DUPLICATE_CHARGE', 'nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:1000']];
        }

        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $validator->errors()->add((string) $key, 'This field is not allowed.');
            }
        }];
    }

    public static function refundStatuses(): array
    {
        return [FundingRefund::PENDING_REVIEW, FundingRefund::APPROVED, FundingRefund::REJECTED, FundingRefund::PROCESSING, FundingRefund::SUCCEEDED, FundingRefund::FAILED];
    }
}
