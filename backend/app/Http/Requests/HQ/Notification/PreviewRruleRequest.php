<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule as RecurrRule;

/**
 * Plan-023 M3 T3.5 — validate POST /hq/{brand}/notifications/schedules/preview-rrule.
 *
 * Used by the composer step 4 "Repeats" picker to render the next 5
 * occurrences in real time. Rate-limited 20/min/user upstream.
 */
class PreviewRruleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rrule' => ['required', 'string', 'max:500'],
            'timezone' => ['required', 'string', 'max:64'],
            'starts_at' => ['required', 'date'],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $tz = (string) $this->input('timezone');
            if (! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $v->errors()->add('timezone', "Timezone [{$tz}] is not a valid IANA name.");

                return;
            }
            try {
                new RecurrRule((string) $this->input('rrule'), new \DateTime((string) $this->input('starts_at'), new \DateTimeZone($tz)), null, $tz);
            } catch (InvalidRRule $e) {
                $v->errors()->add('rrule', "RRULE invalid: {$e->getMessage()}");
            }
        });
    }
}
