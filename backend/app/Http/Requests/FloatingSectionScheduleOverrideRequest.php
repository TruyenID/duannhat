<?php

namespace App\Http\Requests;

use App\Models\FloatingSectionSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shop override of a floating section schedule's displayed time window.
 * Restricted to start_time/end_time/days_of_week — is_active, priority, and
 * the calendar date range stay HQ-owned/toggle-owned, mirroring
 * BranchMenuScheduleUpsertRequest's ALLOWED_FIELDS scope.
 */
class FloatingSectionScheduleOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_time' => ['sometimes', 'date_format:H:i,H:i:s'],
            'end_time' => ['sometimes', 'date_format:H:i,H:i:s'],
            'days_of_week' => ['sometimes', 'integer', 'min:1', 'max:127'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $hasStart = $this->has('start_time');
        $hasEnd = $this->has('end_time');
        $hasDays = $this->has('days_of_week');

        if (! $hasStart && ! $hasEnd && ! $hasDays) {
            $validator->after(fn (Validator $v) => $v->errors()->add(
                'start_time', 'At least one of start_time, end_time, or days_of_week is required.'
            ));

            return;
        }

        if ($hasStart === $hasEnd) {
            return;
        }

        $validator->after(function (Validator $validator) use ($hasEnd) {
            if ($validator->errors()->has('end_time') || $validator->errors()->has('start_time')) {
                return;
            }

            // The shop schedule routes are plain Route::put/delete calls (no
            // route-model-binding), so the route param is the raw id string
            // here — resolve it manually, unlike FloatingSectionScheduleUpdateRequest
            // which rides HQ's ->scoped() apiResource binding.
            $schedule = FloatingSectionSchedule::find((string) $this->route('schedule'));

            if ($hasEnd) {
                $existingStart = $schedule?->getRawOriginal('start_time');
                if ($existingStart !== null && strtotime($this->input('end_time')) <= strtotime($existingStart)) {
                    $validator->errors()->add('end_time', __('validation.after', [
                        'attribute' => 'end time',
                        'date' => 'start time',
                    ]));
                }
            } else {
                $existingEnd = $schedule?->getRawOriginal('end_time');
                if ($existingEnd !== null && strtotime($this->input('start_time')) >= strtotime($existingEnd)) {
                    $validator->errors()->add('start_time', __('validation.before', [
                        'attribute' => 'start time',
                        'date' => 'end time',
                    ]));
                }
            }
        });
    }
}
