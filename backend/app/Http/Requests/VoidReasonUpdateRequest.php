<?php

/**
 * VoidReason Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Enums\VoidStockEffectEnum;
use App\Omnify\Modules\VoidReason\Requests\VoidReasonUpdateRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * plan-051 (#1149) — all fields optional (partial update). Deactivation is
 * an update with is_active=false — there is no delete endpoint.
 */
class VoidReasonUpdateRequest extends VoidReasonUpdateRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id and brand_id are resolved server-side — never accept from client.
        unset($rules['organization_id'], $rules['brand_id']);

        $rules['label'] = ['sometimes', 'nullable', 'string', 'max:100'];

        // 'sometimes' prevents validated() from including these as null when
        // a locale is absent from the request (see VoidReasonStoreRequest).
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.label"] = ['sometimes', 'nullable', 'string', 'max:100'];
        }

        $rules['stock_effect'] = ['sometimes', Rule::in(VoidStockEffectEnum::values())];
        $rules['requires_note'] = ['sometimes', 'boolean'];
        $rules['is_active'] = ['sometimes', 'boolean'];
        $rules['sort_order'] = ['sometimes', 'integer', 'min:0'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->hasAny(['ja', 'en', 'vi'])) {
                return;
            }
            $ja = $this->scalarInput('ja.label');
            $en = $this->scalarInput('en.label');
            $vi = $this->scalarInput('vi.label');
            if ($ja === '' && $en === '' && $vi === '') {
                $v->errors()->add('ja.label', 'The label field is required in at least one language (ja, en, or vi).');
            }
        });
    }

    /** Trim a request input to string, treating any non-scalar (array/object) as empty. */
    private function scalarInput(string $key): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
