<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /hq/{brand}/notifications/audiences` body.
 *
 * Delegates rule-JSON shape validation to `AudienceRuleValidator` so the
 * Store / Update / Preview / Broadcast requests share one source of truth.
 */
class StoreAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // `brand_id` is intentionally NOT a rule here. The route brand
        // (resolved by `ResolveBrandFromSlug` from the URL slug) is the
        // single source of truth — accepting `brand_id` from the request
        // body would let an admin pin an audience to another brand
        // (cross-brand metadata pollution, see #171).
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
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
