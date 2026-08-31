<?php

namespace App\Http\Requests\Me\Notification;

use DateTimeZone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQuietHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'to' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'tz' => ['required', 'string', 'max:60'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $tz = $this->input('tz');
            if (is_string($tz) && $tz !== '' && ! in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                $v->errors()->add('tz', "Unknown IANA timezone: {$tz}");
            }
        });
    }
}
