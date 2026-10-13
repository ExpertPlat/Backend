<?php

namespace App\Http\Requests\Commerce;

use App\Models\Agreement;
use App\Models\Booking;
use App\Models\Expert;
use App\Models\Proposal;
use App\Services\Commerce\Agreements;
use App\Services\Commerce\Proposals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Expert-realm Sprint 4 requests. Foreign ids are 404; unknown fields are rejected (no mass assignment of state). */
class ExpertCommerceRequest extends FormRequest
{
    private const START_AT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/D';

    public function expert(): Expert
    {
        $actor = $this->user();
        $token = $actor?->currentAccessToken();
        abort_unless($actor !== null && $token !== null && $token->tokenable_type === Expert::class, 403);

        return Expert::query()->findOrFail($actor->getAuthIdentifier());
    }

    public function authorize(): bool
    {
        $expert = $this->expert();
        if ($this->route('invitation')) {
            Gate::authorize('respond', $expert->invitations()->findOrFail($this->route('invitation')));
        }
        foreach ([['proposal', Proposal::class], ['agreement', Agreement::class], ['booking', Booking::class]] as [$param, $model]) {
            if ($this->route($param)) {
                Gate::authorize('act', $model::where('expert_id', $expert->id)->findOrFail($this->route($param)));
            }
        }

        return true;
    }

    public function rules(): array
    {
        $name = (string) $this->route()->getName();
        if ($this->isMethod('GET')) {
            return ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'status' => ['sometimes', 'string', Rule::in(['submitted', 'change_requested', 'accepted', 'rejected', 'withdrawn', 'expired', 'awaiting_payment', 'cancelled', 'held', 'confirmed'])]];
        }
        $version = ['expectedVersion' => ['required', 'integer', 'min:1']];

        return match (true) {
            str_ends_with($name, 'invitations.proposal.store') => ProposalContent::rules(),
            str_ends_with($name, 'proposals.versions.store') => $version + ProposalContent::rules(),
            str_ends_with($name, 'proposals.withdraw') => $version + ['reasonCode' => ['sometimes', 'nullable', Rule::in(Proposals::WITHDRAW_REASONS)]],
            str_ends_with($name, 'agreements.cancel'), str_ends_with($name, 'bookings.cancel') => $version + ['reasonCode' => ['required', Rule::in(Agreements::CANCEL_REASONS)]],
            str_ends_with($name, 'bookings.reschedule') => $version + ['startAt' => ['required', 'string', 'regex:'.self::START_AT]],
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
            if (isset($this->rules()['milestones'])) {
                ProposalContent::check($validator, $this->all());
            }
        }];
    }
}
