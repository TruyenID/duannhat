<?php

/**
 * PaymentMethod Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\PaymentMethod\Requests\PaymentMethodUpdateRequestBase;
use Illuminate\Validation\Validator;

class PaymentMethodUpdateRequest extends PaymentMethodUpdateRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id is injected server-side from HasOrganizationContext
        unset($rules['organization_id']);

        // code is immutable after creation — reject explicitly so the client gets a clear error.
        $rules['code'] = ['prohibited'];

        $rules['name'] = ['sometimes', 'nullable', 'string', 'max:255'];

        // 'sometimes' prevents validated() from including these as null when a locale
        // is absent from the request — avoids Astrotomic inserting a row with name=null.
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:255'];
        }

        // sort_order is managed server-side via the reorder endpoint; not accepted on update
        unset($rules['sort_order']);

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                $hasLocaleKey = $this->hasAny(['ja', 'en', 'vi']);
                if (! $hasLocaleKey) {
                    return;
                }
                $localeFilled = filled($this->input('ja.name'))
                    || filled($this->input('en.name'))
                    || filled($this->input('vi.name'));

                if (! $this->filled('name') && ! $localeFilled) {
                    $v->errors()->add('name', 'The name field is required in at least one language (ja, en, or vi).');
                }
            },
        ];
    }
}
