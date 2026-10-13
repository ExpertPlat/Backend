<?php

namespace App\Http\Requests\Payments;

use App\Http\Requests\Cases\CaseRequest;
use App\Services\Payments\Refunds;
use Illuminate\Validation\Rule;

/**
 * User-realm Sprint 5 requests (Case-bound; ownership and policy come from CaseRequest). Unknown fields are rejected, which is how
 * client-supplied amount, currency, commission, destination or version values are refused: the backend derives every money value.
 */
class PaymentRequest extends CaseRequest
{
    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100']];
        }
        $name = (string) $this->route()->getName();
        if (str_ends_with($name, 'refund-requests.store')) {
            return ['reasonCode' => ['required', Rule::in(Refunds::USER_REASONS)], 'choice' => ['required', Rule::in(Refunds::CHOICES)], 'note' => ['sometimes', 'nullable', 'string', 'max:1000']];
        }

        return []; // funding, checkout and sync take no client input: the Agreement/Funding in the path is the only identifier
    }
}
