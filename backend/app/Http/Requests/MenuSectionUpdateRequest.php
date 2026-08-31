<?php

/**
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\MenuSection\Requests\MenuSectionUpdateRequestBase;
use Illuminate\Contracts\Validation\ValidationRule;

class MenuSectionUpdateRequest extends MenuSectionUpdateRequestBase
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id and brand_id are never accepted from the client.
        unset($rules['organization_id'], $rules['brand_id']);

        $rules['name'] = ['sometimes', 'string', 'max:255', 'not_regex:/^\s*$/u'];
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules["{$locale}.name"] = ["required_with:{$locale}", 'string', 'max:255', 'not_regex:/^\s*$/u'];
        }
        $rules['updated_at'] = ['sometimes', 'required', 'date'];

        return $rules;
    }
}
