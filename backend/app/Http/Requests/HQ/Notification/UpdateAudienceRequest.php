<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // `brand_id` is intentionally NOT a rule here — see StoreAudienceRequest.
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string'],
            'rule' => ['sometimes', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        if (! $this->has('rule')) {
            return;
        }

        $validator->after(function ($v) {
            foreach (AudienceRuleValidator::errors((array) $this->input('rule', [])) as $field => $msg) {
                $v->errors()->add("rule.{$field}", $msg);
            }
        });
    }
}
