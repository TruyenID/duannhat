<?php

namespace App\Http\Requests;

use App\Models\BranchScheduleOverride;
use App\Models\MenuSchedule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class BranchMenuScheduleUpsertRequest extends FormRequest
{
    /**
     * Shop overrides cover timing, days-of-week and the calendar window
     * (#1970 — HQ sets the dates, the shop may narrow or shift them). HQ still
     * owns is_active and priority — attempts to override those must fail 422 so
     * clients don't silently lose data.
     */
    private const ALLOWED_FIELDS = ['start_time', 'end_time', 'days_of_week', 'start_date', 'end_date', 'days_of_month'];

    public function authorize(): bool
    {
        return true; // gate checked in controller via $this->authorize()
    }

    public function rules(): array
    {
        return [
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            // Bitmask bit0=Sun … bit6=Sat; 1–127 (0 = no day would hide the
            // window forever — use is_active for that). null = reset to HQ days.
            'days_of_week' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:127'],
            // Calendar window (#1970). null = reset to the HQ date, which is
            // itself NULL-means-unbounded — so a shop can narrow or shift a
            // window HQ set, but cannot clear one HQ still has.
            // Day-of-month mask (#1979), read only when HQ's kind is Monthly.
            // The KIND stays HQ-owned: flipping it at a branch would reinterpret
            // every other column rather than adjust it.
            'days_of_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Cross-field validation on effective (COALESCED) values.
     *
     * A branch may send only one field at a time (partial override). The effective
     * value for each field is resolved as:
     *   - Field sent as non-null → use the sent value
     *   - Field sent as null → reset to HQ default (effective = HQ value)
     *   - Field absent from request → keep existing override if any, else HQ default
     *
     * Validation fails when effective_start >= effective_end after COALESCE.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            foreach ($this->except(self::ALLOWED_FIELDS) as $field => $_) {
                $v->errors()->add(
                    (string) $field,
                    "Field '{$field}' is not allowed for branch overrides."
                );
            }
        });

        $validator->after(function ($v) {
            /** @var MenuSchedule $schedule */
            $schedule = $this->route('schedule');
            // Branch is resolved from request attributes (set by ResolveShopFromSlug
            // middleware under the 'shop' key), not from route params (shopSlug is
            // forgotten after middleware resolves it). The controller reads the same key.
            $branch = $this->attributes->get('shop');

            if (! $schedule || ! $branch) {
                return;
            }

            $existing = BranchScheduleOverride::where('menu_schedule_id', $schedule->id)
                ->where('branch_id', $branch->id)
                ->first();

            // Resolve effective start_time after COALESCE.
            if ($this->has('start_time')) {
                $effectiveStart = $this->input('start_time') ?? $schedule->getRawOriginal('start_time');
            } else {
                $effectiveStart = $existing?->getRawOriginal('start_time')
                    ?? $schedule->getRawOriginal('start_time');
            }

            // Resolve effective end_time after COALESCE.
            if ($this->has('end_time')) {
                $effectiveEnd = $this->input('end_time') ?? $schedule->getRawOriginal('end_time');
            } else {
                $effectiveEnd = $existing?->getRawOriginal('end_time')
                    ?? $schedule->getRawOriginal('end_time');
            }

            if ($effectiveStart && $effectiveEnd && $effectiveStart >= $effectiveEnd) {
                $v->errors()->add('end_time', 'Effective end time must be after effective start time.');
            }

            // Same COALESCE resolution for the calendar window (#1970). Dates are
            // INCLUSIVE bounds, so start == end is a valid one-day campaign —
            // only start > end is empty. Both sides may legitimately stay NULL
            // (unbounded), in which case there is nothing to compare.
            $effectiveStartDate = $this->resolveEffectiveDate('start_date', $schedule, $existing);
            $effectiveEndDate = $this->resolveEffectiveDate('end_date', $schedule, $existing);

            if ($effectiveStartDate && $effectiveEndDate && $effectiveStartDate > $effectiveEndDate) {
                $v->errors()->add('end_date', 'Effective end date must not be before effective start date.');
            }
        });
    }

    /**
     * Effective value of a date column after COALESCE, using the same three-way
     * rule as the time fields: sent non-null → that value; sent null → HQ value;
     * absent → existing override if any, else HQ value.
     *
     * Returns the raw `Y-m-d` string (or null) so callers compare lexically,
     * which is ordering-correct for ISO dates and avoids Carbon parsing.
     */
    private function resolveEffectiveDate(
        string $field,
        MenuSchedule $schedule,
        ?BranchScheduleOverride $existing,
    ): ?string {
        if ($this->has($field)) {
            return $this->input($field) ?? $schedule->getRawOriginal($field);
        }

        return $existing?->getRawOriginal($field) ?? $schedule->getRawOriginal($field);
    }
}
