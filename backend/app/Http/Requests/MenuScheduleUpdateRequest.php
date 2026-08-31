<?php

namespace App\Http\Requests;

use App\Models\MenuSchedule;
use App\Omnify\Enums\MenuScheduleRecurrenceEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MenuScheduleUpdateRequest extends FormRequest
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
            // Switching kind is allowed; the columns belonging to the OTHER
            // kinds are deliberately left on the row (BR-MSD03) so flipping back
            // and forth does not destroy what the user typed.
            'recurrence_kind' => ['sometimes', Rule::enum(MenuScheduleRecurrenceEnum::class)],
            'days_of_month' => [
                Rule::requiredIf(fn () => $this->recurrenceKind() === MenuScheduleRecurrenceEnum::Monthly
                    && $this->has('recurrence_kind')),
                'sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647',
            ],
            'specific_dates' => [
                Rule::requiredIf(fn () => $this->recurrenceKind() === MenuScheduleRecurrenceEnum::SpecificDates
                    && $this->has('recurrence_kind')),
                'sometimes', 'nullable', 'array',
            ],
            'specific_dates.*' => ['date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            // Optional calendar-date bounds (TC-MSCH-103). Nullable so a PATCH can
            // clear a bound by sending null. end_date >= start_date enforced only
            // when both are present in the request.
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    /**
     * The kind this row will have after the update: what the request asks for,
     * else what is already persisted, else Weekly. A PATCH that only touches the
     * times must not be judged against the wrong kind.
     */
    protected function recurrenceKind(): MenuScheduleRecurrenceEnum
    {
        if ($this->has('recurrence_kind')) {
            return MenuScheduleRecurrenceEnum::tryFrom((string) $this->input('recurrence_kind'))
                ?? MenuScheduleRecurrenceEnum::Weekly;
        }

        $schedule = $this->route('schedule');

        return $schedule instanceof MenuSchedule
            // getRawOriginal: the attribute is cast to the enum already, and a
            // (string) cast on an enum is a fatal rather than a fallback.
            ? (MenuScheduleRecurrenceEnum::tryFrom((string) $schedule->getRawOriginal('recurrence_kind')) ?? MenuScheduleRecurrenceEnum::Weekly)
            : MenuScheduleRecurrenceEnum::Weekly;
    }

    /**
     * Cross-field time validation when only one of start_time / end_time is sent.
     * The declarative `after:start_time` rule only works when both fields are present
     * in the request; when one is absent (PATCH semantics) we must compare against
     * the persisted DB value explicitly.
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

            /** @var MenuSchedule $schedule */
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
