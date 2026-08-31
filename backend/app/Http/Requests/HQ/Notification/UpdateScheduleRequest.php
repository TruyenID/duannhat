<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule as RecurrRule;

/**
 * Plan-023 M3 T3.5 — validate PATCH /hq/{brand}/notifications/schedules/{id}.
 *
 * Every field is optional (partial update). RRULE + timezone are
 * re-validated when either is present so the tick worker never inherits
 * a half-edited shape that crashes mid-tick.
 */
class UpdateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_key' => ['sometimes', 'string', 'max:100'],
            'audience_id' => ['sometimes', 'uuid'],
            'channels' => ['sometimes', 'array', 'min:1'],
            'channels.*' => ['string', 'in:in_app,realtime,email,push'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'params' => ['sometimes', 'nullable', 'array'],
            'rrule' => ['sometimes', 'string', 'max:500'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'occurrences_remaining' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->has('timezone')) {
                $tz = (string) $this->input('timezone');
                if (! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                    $v->errors()->add('timezone', "Timezone [{$tz}] is not a valid IANA name.");

                    return;
                }
            }

            if ($this->has('rrule')) {
                $tz = (string) $this->input('timezone', 'UTC');
                $rrule = (string) $this->input('rrule');
                try {
                    new RecurrRule($rrule, new \DateTime('now', new \DateTimeZone($tz)), null, $tz);
                } catch (InvalidRRule $e) {
                    $v->errors()->add('rrule', "RRULE invalid: {$e->getMessage()}");
                }
            }
        });
    }
}
