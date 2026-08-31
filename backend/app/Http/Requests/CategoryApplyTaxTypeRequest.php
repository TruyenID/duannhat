<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * #1074 — bulk-assign a tax type to every product in a category.
 *
 * `tax_type_id` must be present but may be null (null clears the per-product
 * override so the products fall back to inheritance). Brand/org ownership of
 * the tax type is enforced in the mutation boundary, not here.
 */
class CategoryApplyTaxTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'tax_type_id' => ['present', 'nullable', 'uuid'],
        ];
    }
}
