<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Foundation\Http\FormRequest;

class PreviewAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rule' => ['required', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            foreach (AudienceRuleValidator::errors((array) $this->input('rule', [])) as $field => $msg) {
                $v->errors()->add("rule.{$field}", $msg);
            }
        });
    }
}
