<?php

/**
 * Material Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Material\Requests\MaterialStoreRequestBase;
use Illuminate\Validation\Validator;

/**
 * MaterialStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class MaterialStoreRequest extends MaterialStoreRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id is injected by the controller from the request
        // context (brand → organization), not supplied by the client.
        unset($rules['organization_id']);

        // Translatable `name` may be provided either at the top level or under
        // ja/en/vi — the after-validator below checks at least one is present.
        $rules['name'] = ['nullable', 'string', 'max:255'];

        // is_active defaults to true in prepareForValidation, so the schema
        // rule of ['required', 'boolean'] is relaxed to nullable.
        $rules['is_active'] = ['nullable', 'boolean'];

        // yield_quantity is the amount produced per batch — it must be strictly
        // positive. The schema rule is only ['required', 'numeric'], which let
        // negatives (e.g. -5) through and corrupted cost/production math
        // (plan-040 QA finding F3). Pin it to > 0.
        $rules['yield_quantity'] = ['required', 'numeric', 'gt:0'];

        // Optional flags that the form may omit on create.
        $rules['requires_temperature_check'] = ['nullable', 'boolean'];

        // temperature_min/max are stored as decimal(5,2) — a value beyond
        // ±999.99 overflows the column and surfaces as a raw QueryException
        // (leaking SQL to the client). Bound them to the column range so an
        // out-of-range input returns a clean 422 instead.
        $rules['temperature_min'] = ['nullable', 'numeric', 'between:-999.99,999.99'];
        $rules['temperature_max'] = ['nullable', 'numeric', 'between:-999.99,999.99'];

        // allergen_ids is a custom pivot payload consumed by
        // MaterialService::create to sync material_allergens.
        $rules['allergen_ids'] = ['sometimes', 'array'];
        $rules['allergen_ids.*'] = ['uuid', 'exists:allergens,id'];

        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["{$locale}.description"] = ['sometimes', 'nullable', 'string'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $flat = trim((string) $this->input('name', ''));
            $ja = trim((string) $this->input('ja.name', ''));
            $en = trim((string) $this->input('en.name', ''));
            $vi = trim((string) $this->input('vi.name', ''));
            if ($flat === '' && $ja === '' && $en === '' && $vi === '') {
                $v->errors()->add('ja.name', 'The name field is required in at least one language (ja, en, or vi).');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'is_active' => true,
            'requires_temperature_check' => false,
        ]);
    }
}
