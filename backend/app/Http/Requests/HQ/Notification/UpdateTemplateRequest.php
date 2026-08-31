<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'string', 'max:100'],
            'content' => ['sometimes', 'array'],
            'content.ja.title' => ['required_with:content', 'string'],
            'content.ja.body' => ['required_with:content', 'string'],
            'content.en.title' => ['required_with:content', 'string'],
            'content.en.body' => ['required_with:content', 'string'],
            'content.vi.title' => ['required_with:content', 'string'],
            'content.vi.body' => ['required_with:content', 'string'],
            'default_channels' => ['sometimes', 'nullable', 'array'],
            'default_channels.*' => ['string', 'in:in_app,realtime,email,push'],
            'params_schema' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
