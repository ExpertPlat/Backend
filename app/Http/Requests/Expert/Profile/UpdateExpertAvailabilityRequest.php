<?php

namespace App\Http\Requests\Expert\Profile;

use App\Services\Catalog\CatalogTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateExpertAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'timezone' => ['required', 'string', 'timezone:all'],
            'serviceModes' => ['required', 'array', 'min:1', 'max:5'],
            'serviceModes.*' => ['required', Rule::in(CatalogTaxonomy::deliveryModes()), 'distinct:strict'],
            'weeklySchedule' => ['required', 'array', 'min:1', 'max:7'],
            'weeklySchedule.*.day' => ['required', Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']), 'distinct:strict'],
            'weeklySchedule.*.enabled' => ['required', 'boolean'],
            'weeklySchedule.*.windows' => ['present', 'array', 'max:3'],
            'weeklySchedule.*.windows.*.start' => ['required', 'date_format:H:i'],
            'weeklySchedule.*.windows.*.end' => ['required', 'date_format:H:i'],
            'blackoutDates' => ['sometimes', 'array', 'max:90'],
            'blackoutDates.*' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'distinct:strict'],
            'maxActiveRequests' => ['required', 'integer', 'min:1', 'max:50'],
            'responseTimeHours' => ['required', 'integer', 'min:1', 'max:336'],
            'acceptingNewRequests' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->input('weeklySchedule', []) as $dayIndex => $day) {
                $windows = is_array($day['windows'] ?? null) ? $day['windows'] : [];

                if (($day['enabled'] ?? false) && $windows === []) {
                    $validator->errors()->add(
                        "weeklySchedule.{$dayIndex}.windows",
                        'At least one time window is required for an enabled day.',
                    );
                }

                foreach ($windows as $windowIndex => $window) {
                    $start = $window['start'] ?? null;
                    $end = $window['end'] ?? null;

                    if (is_string($start) && is_string($end) && $end <= $start) {
                        $validator->errors()->add(
                            "weeklySchedule.{$dayIndex}.windows.{$windowIndex}.end",
                            'The end time must be after the start time.',
                        );
                    }
                }
            }
        }];
    }
}
