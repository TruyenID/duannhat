<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Foundation\Http\FormRequest;

class RenderTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_id' => ['required_without:template_inline', 'uuid'],
            'template_inline' => ['required_without:template_id', 'array'],
            'template_inline.content' => ['required_with:template_inline', 'array'],
            'params' => ['nullable', 'array'],
        ];
    }
}
