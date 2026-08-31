<?php

/**
 * PaymentMethod Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\PaymentMethod\Requests\PaymentMethodStoreRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PaymentMethodStoreRequest extends PaymentMethodStoreRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id is injected server-side from HasOrganizationContext
        unset($rules['organization_id']);

        $rules['code'] = [
            'required', 'string', 'max:50',
            'regex:/^[a-z0-9_]+$/',
            Rule::unique('payment_methods', 'code')
                ->where(fn ($q) => $q->where('organization_id', request()->attributes->get('organization_id'))),
        ];

        // name is nullable at field level — per-locale nested keys carry the source of truth
        $rules['name'] = ['nullable', 'string', 'max:255'];
        $rules['is_active'] = ['nullable', 'boolean'];

        // 'sometimes' prevents validated() from including these as null when a locale
        // is absent from the request — avoids Astrotomic inserting a row with name=null.
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:255'];
        }

        // sort_order is assigned server-side on create; not accepted from user input
        unset($rules['sort_order']);

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                // Frontend sends nested { ja: { name: '...' } } format via buildI18nPayload().
                $localeFilled = filled($this->input('ja.name'))
                    || filled($this->input('en.name'))
                    || filled($this->input('vi.name'));

                if (! $this->filled('name') && ! $localeFilled) {
                    $v->errors()->add('name', 'The name field is required in at least one language (ja, en, or vi).');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'is_active' => true,
        ]);
    }
}
