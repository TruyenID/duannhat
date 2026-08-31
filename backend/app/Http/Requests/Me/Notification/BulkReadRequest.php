<?php

namespace App\Http\Requests\Me\Notification;

use Illuminate\Foundation\Http\FormRequest;

class BulkReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['nullable', 'array'],
            'ids.*' => ['uuid'],
            'all_unread' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $hasIds = is_array($this->input('ids')) && count($this->input('ids')) > 0;
            $allUnread = (bool) $this->input('all_unread', false);

            if ($hasIds === $allUnread) {
                $v->errors()->add(
                    'ids',
                    'Exactly one of a non-empty `ids` array OR `all_unread=true` is required.',
                );
            }
        });
    }
}
