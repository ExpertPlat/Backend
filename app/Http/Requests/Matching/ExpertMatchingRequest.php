<?php

namespace App\Http\Requests\Matching;

use App\Models\Expert;
use App\Services\Matching\Invitations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Expert-realm Offering and Invitation requests. Unknown fields are rejected (no mass assignment of state). */
class ExpertMatchingRequest extends FormRequest
{
    /** The authenticated Expert (realm already enforced by the `expert` middleware; 403 otherwise). */
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
        if ($this->route('offering')) {
            Gate::authorize('manage', $expert->offerings()->findOrFail($this->route('offering')));
        }
        if ($this->route('invitation')) {
            Gate::authorize('respond', $expert->invitations()->findOrFail($this->route('invitation')));
        }

        return true;
    }

    public function rules(): array
    {
        $name = (string) $this->route()->getName();
        if ($this->isMethod('GET')) {
            return ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'status' => ['sometimes', Rule::in(['draft', 'published', 'paused', 'pending', 'accepted', 'declined', 'expired', 'revoked'])], 'unread' => ['sometimes', 'boolean']];
        }
        $version = ['expectedVersion' => ['required', 'integer', 'min:1']];
        $offering = ['deliveryModes' => ['required', 'array', 'min:1', 'max:5'], 'deliveryModes.*' => ['required', 'string', 'max:100', 'distinct'],
            'languages' => ['required', 'array', 'min:1', 'max:4'], 'languages.*' => ['required', 'string', Rule::in(config('case_intake.languages')), 'distinct'],
            'jurisdictionCodes' => ['sometimes', 'nullable', 'array', 'max:20'], 'jurisdictionCodes.*' => ['string', 'max:100', 'distinct'], 'summary' => ['sometimes', 'nullable', 'string', 'max:500']];

        return match (true) {
            str_ends_with($name, 'offerings.store') => ['catalogEntryId' => ['required', 'integer', 'min:1', Rule::exists('catalog_entries', 'id')]] + $offering,
            str_ends_with($name, 'offerings.update') => $version + $offering,
            str_ends_with($name, 'offerings.publish'), str_ends_with($name, 'offerings.pause'), str_ends_with($name, 'invitations.accept') => $version,
            str_ends_with($name, 'invitations.decline') => $version + ['reasonCode' => ['sometimes', 'nullable', Rule::in(Invitations::DECLINE_REASONS)], 'note' => ['sometimes', 'nullable', 'string', 'max:300']],
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
        }];
    }
}
