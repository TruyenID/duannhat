<?php

namespace App\Http\Requests\Me\Notification;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
