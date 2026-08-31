<?php

namespace App\Http\Requests\Me\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['unread', 'read', 'all'])],
            'type' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'include_dismissed' => ['nullable', 'boolean'],
            // Plan-023 M5 T5.2 — group by aggregation_key when true (default).
            // Accept string "true"/"false" + numeric 1/0 to keep parity with
            // Request::boolean() semantics that the controller reads via.
            'collapse' => ['nullable', 'string', 'in:true,false,0,1'],
            // Plan-023 M5 T5.2 — drill into a single collapsed bucket.
            'aggregation_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
