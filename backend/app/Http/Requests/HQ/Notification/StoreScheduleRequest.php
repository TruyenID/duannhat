<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule as RecurrRule;

/**
 * Plan-023 M3 T3.5 — validate POST /hq/{brand}/notifications/schedules.
 *
 * Notes on `brand_id`: never a rule here — the brand is resolved from the
 * URL slug by `ResolveBrandFromSlug` and pinned in the controller (same
 * cross-brand pollution guard as the audience admin).
 *
 * `rrule` round-trips through `\Recurr\Rule` so a malformed RRULE
 * surfaces as a 422 instead of crashing the tick worker hours later.
 */
class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_key' => ['required', 'string', 'max:100'],
            'audience_id' => ['required', 'uuid'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:in_app,realtime,email,push'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'params' => ['nullable', 'array'],
            'rrule' => ['required', 'string', 'max:500'],
            'timezone' => ['required', 'string', 'max:64'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'occurrences_remaining' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $tz = (string) $this->input('timezone');
            if ($tz !== '' && ! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $v->errors()->add('timezone', "Timezone [{$tz}] is not a valid IANA name.");
            }

            $rrule = (string) $this->input('rrule');
            if ($rrule !== '' && $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                try {
                    new RecurrRule($rrule, new \DateTime('now', new \DateTimeZone($tz)), null, $tz);
                } catch (InvalidRRule $e) {
                    $v->errors()->add('rrule', "RRULE invalid: {$e->getMessage()}");
                }
            }
        });
    }
}
