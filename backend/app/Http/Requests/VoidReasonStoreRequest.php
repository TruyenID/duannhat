<?php

/**
 * VoidReason Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Enums\VoidStockEffectEnum;
use App\Omnify\Modules\VoidReason\Requests\VoidReasonStoreRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * plan-051 (#1149) — mirrors TaxTypeStoreRequest: org/brand come from the
 * URL context (never the client), the translatable label arrives in the
 * Astrotomic locale-keyed shape ({ja: {label}, en: {label}, vi: {label}})
 * with at least one locale required.
 */
class VoidReasonStoreRequest extends VoidReasonStoreRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id and brand_id are resolved server-side:
        // - organization_id from the authenticated user (HasOrganizationContext)
        // - brand_id from the {brandSlug} route param (ResolveBrandFromSlug)
        unset($rules['organization_id'], $rules['brand_id']);

        // label is derived from translations (ja→en→vi); the top-level mirror
        // is optional — translations carry the source of truth.
        $rules['label'] = ['nullable', 'string', 'max:100'];

        // 'sometimes' prevents validated() from including these as null when
        // a locale is absent from the request — avoids Astrotomic inserting a
        // translation row with label=null (NOT NULL constraint violation).
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.label"] = ['sometimes', 'nullable', 'string', 'max:100'];
        }

        $rules['stock_effect'] = ['required', Rule::in(VoidStockEffectEnum::values())];

        // Flags optional (schema defaults applied server-side).
        $rules['requires_note'] = ['nullable', 'boolean'];
        $rules['is_active'] = ['nullable', 'boolean'];
        $rules['sort_order'] = ['nullable', 'integer', 'min:0'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $ja = $this->scalarInput('ja.label');
            $en = $this->scalarInput('en.label');
            $vi = $this->scalarInput('vi.label');
            $flat = $this->scalarInput('label');
            if ($ja === '' && $en === '' && $vi === '' && $flat === '') {
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
