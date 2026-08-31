<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100'],
            'content' => ['required', 'array'],
            'content.ja.title' => ['required', 'string'],
            'content.ja.body' => ['required', 'string'],
            'content.en.title' => ['required', 'string'],
            'content.en.body' => ['required', 'string'],
            'content.vi.title' => ['required', 'string'],
            'content.vi.body' => ['required', 'string'],
            'default_channels' => ['nullable', 'array'],
            'default_channels.*' => ['string', 'in:in_app,realtime,email,push'],
            'params_schema' => ['nullable', 'array'],
            // `brand_id` is intentionally NOT a rule here. The route brand
            // (resolved from the URL slug) is the single source of truth.
            // See #171.
        ];
    }
}
