<?php

namespace App\Http\Requests\Me\Notification;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMasterMuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'master_mute' => ['required', 'boolean'],
        ];
    }
}
