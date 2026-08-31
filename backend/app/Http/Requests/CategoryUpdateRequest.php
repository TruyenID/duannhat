<?php

/**
 * Category Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Category\Requests\CategoryUpdateRequestBase;
use Illuminate\Validation\Validator;

/**
 * CategoryUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class CategoryUpdateRequest extends CategoryUpdateRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset($rules['organization_id'], $rules['brand_id']);

        $rules['name'] = ['sometimes', 'nullable', 'string', 'max:100'];
        $rules['is_active'] = ['sometimes', 'boolean'];
        $rules['is_featured'] = ['sometimes', 'boolean'];
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
            if (! $this->hasAny(['ja', 'en', 'vi'])) {
                return;
            }
            $ja = trim((string) $this->input('ja.name', ''));
            $en = trim((string) $this->input('en.name', ''));
            $vi = trim((string) $this->input('vi.name', ''));
            if ($ja === '' && $en === '' && $vi === '') {
                $v->errors()->add('ja.name', 'The name field is required in at least one language (ja, en, or vi).');
            }
        });
    }
}
