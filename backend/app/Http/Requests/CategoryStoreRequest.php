<?php

/**
 * Category Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Category\Requests\CategoryStoreRequestBase;
use Illuminate\Validation\Validator;

/**
 * CategoryStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class CategoryStoreRequest extends CategoryStoreRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset($rules['organization_id'], $rules['brand_id']);

        // `name` is still persisted on the base `categories` row but is
        // derived in CategoryService from ja/en/vi priority when the
        // caller only sent per-locale values. So it's required only when
        // no locale-nested name exists.
        $rules['name'] = ['nullable', 'string', 'max:100'];
        $rules['sku'] = ['nullable', 'string', 'max:50'];
        $rules['is_active'] = ['nullable', 'boolean'];
        $rules['is_featured'] = ['nullable', 'boolean'];
        $rules['parent_id'] = ['nullable', 'uuid', 'exists:categories,id'];

        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:100'];
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
            'is_featured' => false,
        ]);
    }
}
