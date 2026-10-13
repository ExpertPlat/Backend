<?php

namespace App\Http\Requests\Commerce;

use Illuminate\Validation\Validator;

/** Shared proposal-version rules (submit and revise). The client never sends a total: the server computes it. */
final class ProposalContent
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'scopeSummary' => ['required', 'string', 'min:20', 'max:4000'],
            'inputsRequired' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'deliverables' => ['required', 'array', 'min:1', 'max:10'],
            'deliverables.*.title' => ['required', 'string', 'min:3', 'max:200'],
            'deliverables.*.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'exclusions' => ['sometimes', 'array', 'max:15'],
            'exclusions.*' => ['required', 'string', 'min:3', 'max:300'],
            'clarificationAllowance' => ['required', 'integer', 'min:0', 'max:10'],
            'reviewPeriodHours' => ['required', 'integer', 'min:24', 'max:336'],
            'consultationMinutes' => ['sometimes', 'nullable', 'integer', 'min:15', 'max:240'],
            'currency' => ['required', 'string', 'size:3'],
            'milestones' => ['required', 'array', 'min:1', 'max:10'],
            'milestones.*.title' => ['required', 'string', 'min:3', 'max:200'],
            'milestones.*.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'milestones.*.amountMinor' => ['required', 'integer', 'min:1'],
            'milestones.*.dueAt' => ['sometimes', 'nullable', 'date', 'after:now'],
            'validityDays' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ];
    }

    /** Integer-only money and granularity-aligned session length; floats and numeric strings are rejected. */
    public static function check(Validator $validator, array $input): void
    {
        foreach ($input['milestones'] ?? [] as $i => $m) {
            if (is_array($m) && array_key_exists('amountMinor', $m) && ! is_int($m['amountMinor'])) {
                $validator->errors()->add("milestones.$i.amountMinor", 'The amount must be an integer in minor units.');
            }
        }
        $minutes = $input['consultationMinutes'] ?? null;
        if ($minutes !== null && (! is_int($minutes) || $minutes % (int) config('commerce.slot_granularity_minutes') !== 0)) {
            $validator->errors()->add('consultationMinutes', 'The session length must be a multiple of '.config('commerce.slot_granularity_minutes').' minutes.');
        }
    }
}
