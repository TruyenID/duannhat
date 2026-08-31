<?php

namespace App\Http\Requests\Me\Notification;

use Illuminate\Foundation\Http\FormRequest;

class BulkDismissRequest extends FormRequest
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
            'all_read' => ['nullable', 'boolean'],
            'all' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $hasIds = is_array($this->input('ids')) && count($this->input('ids')) > 0;
            $allRead = (bool) $this->input('all_read', false);
            $all = (bool) $this->input('all', false);

            $flagsSet = (int) $hasIds + (int) $allRead + (int) $all;

            if ($flagsSet !== 1) {
                $v->errors()->add(
                    'ids',
                    'Exactly one of a non-empty `ids` array, `all_read=true`, or `all=true` is required.',
                );
            }
        });
    }
}
