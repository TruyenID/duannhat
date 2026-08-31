<?php

/**
 * Allergen Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Allergen\Requests\AllergenStoreRequestBase;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class AllergenStoreRequest extends AllergenStoreRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id injected server-side from HasOrganizationContext.
        unset($rules['organization_id']);

        // Composite uniqueness on (code, jurisdiction) is enforced by the
        // DB index — surface as a friendly validation error here as well.
        $rules['code'] = [
            'required', 'string', 'max:60',
            'regex:/^[a-z0-9_]+$/',
            Rule::unique('allergens', 'code')
                ->where(fn ($q) => $q->where('jurisdiction', $this->input('jurisdiction'))),
        ];
        $rules['is_active'] = ['nullable', 'boolean'];

        // `name` may be delivered as a flat top-level string OR via locale
        // flat keys (`name:ja`, `name:en`, `name:vi`). Downgrade to nullable
        // so the per-locale shape is accepted; at least one locale name is
        // still required via a custom rule below.
        $rules['name'] = ['nullable', 'string', 'max:120'];
        $rules['name:ja'] = ['nullable', 'string', 'max:120'];
        $rules['name:en'] = ['nullable', 'string', 'max:120'];
        $rules['name:vi'] = ['nullable', 'string', 'max:120'];

        $rules['jurisdiction'] = ['required', 'string', Rule::in(['jp', 'eu', 'us'])];
        $rules['severity'] = ['required', 'string', Rule::in(['mandatory', 'recommended'])];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $localeFilled = $this->filled('name:ja')
                || $this->filled('name:en')
                || $this->filled('name:vi');

            if (! $this->filled('name') && ! $localeFilled) {
                $v->errors()->add('name', 'The name field is required.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'is_active' => true,
            'severity' => 'mandatory',
        ]);
    }
}
