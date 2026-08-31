<?php

namespace App\Http\Requests;

use App\Omnify\Enums\MenuScheduleRecurrenceEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuScheduleStoreRequest extends FormRequest
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
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'date_format:H:i,H:i:s', 'after:start_time'],
            // Required only for the weekly kind — a monthly or specific-dates
            // row has no weekday to give, and demanding one would force the UI
            // to send a meaningless 127 (#1979).
            'recurrence_kind' => ['sometimes', Rule::enum(MenuScheduleRecurrenceEnum::class)],
            'days_of_week' => [
                Rule::requiredIf(fn () => $this->recurrenceKind() === MenuScheduleRecurrenceEnum::Weekly),
                'integer', 'min:1', 'max:127',
            ],
            // Bitmask bit0 = the 1st … bit30 = the 31st. 2_147_483_647 is all 31
            // bits set; 0 would hide the window forever, same reasoning as
            // days_of_week's min:1.
            'days_of_month' => [
                Rule::requiredIf(fn () => $this->recurrenceKind() === MenuScheduleRecurrenceEnum::Monthly),
                'nullable', 'integer', 'min:1', 'max:2147483647',
            ],
            'specific_dates' => [
                Rule::requiredIf(fn () => $this->recurrenceKind() === MenuScheduleRecurrenceEnum::SpecificDates),
                'nullable', 'array',
            ],
            'specific_dates.*' => ['date_format:Y-m-d'],
            'is_active' => ['boolean'],
            'priority' => ['integer', 'min:0'],
            // Optional calendar-date bounds (TC-MSCH-103). end_date >= start_date
            // is only enforced when start_date is also supplied.
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    /**
     * The kind this request is asking for. Absent means Weekly — every row that
     * existed before #1979 was weekly, so that is the only reading that keeps
     * an old client's payload meaning what it used to.
     */
    protected function recurrenceKind(): MenuScheduleRecurrenceEnum
    {
        return MenuScheduleRecurrenceEnum::tryFrom((string) $this->input('recurrence_kind'))
            ?? MenuScheduleRecurrenceEnum::Weekly;
    }
}
