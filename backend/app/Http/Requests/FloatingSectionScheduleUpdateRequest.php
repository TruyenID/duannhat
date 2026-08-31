<?php

namespace App\Http\Requests;

use App\Models\FloatingSectionSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FloatingSectionScheduleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['sometimes', 'date_format:H:i,H:i:s'],
            'end_time' => ['sometimes', 'date_format:H:i,H:i:s', 'after:start_time'],
            'days_of_week' => ['sometimes', 'integer', 'min:1', 'max:127'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    /**
     * Cross-field time validation when only one of start_time / end_time is sent.
     * Mirrors MenuScheduleUpdateRequest.
     */
    public function withValidator(Validator $validator): void
    {
        $hasStart = $this->has('start_time');
        $hasEnd = $this->has('end_time');

        if ($hasStart === $hasEnd) {
            return;
        }

        $validator->after(function (Validator $validator) use ($hasEnd) {
            if ($validator->errors()->has('end_time') || $validator->errors()->has('start_time')) {
                return;
            }

            /** @var FloatingSectionSchedule $schedule */
            $schedule = $this->route('schedule');

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
