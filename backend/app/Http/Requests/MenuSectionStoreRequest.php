<?php

/**
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\MenuSection\Requests\MenuSectionStoreRequestBase;
use Illuminate\Contracts\Validation\ValidationRule;

class MenuSectionStoreRequest extends MenuSectionStoreRequestBase
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id and brand_id are injected by the controller from
        // the ResolveBrandFromSlug route context — never accepted from the client.
        unset($rules['organization_id'], $rules['brand_id']);

        // #1185 — is_featured has a column default (false). The generator marks
        // every boolean `required`, which broke EVERY create call that predates
        // the field (HQ, shop, seeders, tests): 422 "The Featured field is
        // required" on a plain {name} POST. Optional here so the default applies.
        $rules['is_featured'] = ['sometimes', 'boolean'];

        $rules['name'] = ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'];
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules["{$locale}.name"] = ["required_with:{$locale}", 'string', 'max:255', 'not_regex:/^\s*$/u'];
        }

        return $rules;
    }
}
