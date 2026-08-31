<?php

/**
 * MenuPromotion Update Request — plan-019.
 *
 * BR-MP-LOCK lives in MenuPromotionService::assertNotLocked, which
 * raises MenuPromotionException::lockedField with a structured 422
 * + items_with_promotion_count meta. This request only handles
 * SHAPE + cross-field invariants.
 *
 * SAFE TO EDIT - Plan-019 only.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\MenuPromotion\Requests\MenuPromotionUpdateRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MenuPromotionUpdateRequest extends MenuPromotionUpdateRequestBase
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset($rules['organization_id'], $rules['brand_id'], $rules['branch_id']);

        $rules['name'] = ['nullable', 'string', 'max:255'];
        $rules['description'] = ['nullable', 'string'];
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["{$locale}.description"] = ['sometimes', 'nullable', 'string'];
            $rules["name:{$locale}"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["description:{$locale}"] = ['sometimes', 'nullable', 'string'];
        }

        $rules['discount_percent'] = ['sometimes', 'required', 'numeric', 'between:0.01,100'];
        $rules['applies_to'] = ['sometimes', 'required', Rule::in(['all_items', 'categories', 'products', 'mixed'])];
        $rules['daily_time_from'] = ['sometimes', 'nullable', 'date_format:H:i,H:i:s'];
        $rules['daily_time_to'] = ['sometimes', 'nullable', 'date_format:H:i,H:i:s'];
        $rules['weekdays'] = ['sometimes', 'nullable', 'array'];
        $rules['weekdays.*'] = ['integer', 'between:1,7'];
        $rules['valid_from'] = ['sometimes', 'required', 'date'];
        $rules['valid_until'] = ['sometimes', 'required', 'date', 'after:valid_from'];
        $rules['stacking_mode'] = ['sometimes', 'nullable', Rule::in(['exclusive_with_coupons', 'stackable_with_coupons'])];
        $rules['is_active'] = ['sometimes', 'nullable', 'boolean'];

        $rules['applicable_category_ids'] = ['sometimes', 'array'];
        $rules['applicable_category_ids.*'] = ['uuid', 'exists:categories,id'];
        $rules['applicable_product_ids'] = ['sometimes', 'array'];
        $rules['applicable_product_ids.*'] = ['uuid', 'exists:products,id'];

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                // daily_time_from/to must come as a pair (both null or both set).
                $from = $this->input('daily_time_from');
                $to = $this->input('daily_time_to');
                if (($from === null) !== ($to === null)) {
                    $v->errors()->add('daily_time_to', 'daily_time_from and daily_time_to must both be set or both be null.');
                }

                // Scope coherence (only when applies_to is being changed AND the pivots ARE in the payload).
                $appliesTo = $this->input('applies_to');
                if ($appliesTo === null) {
                    return;
                }
                $cats = $this->input('applicable_category_ids');
                $prods = $this->input('applicable_product_ids');
                if ($appliesTo === 'categories' && $cats !== null && (! is_array($cats) || $cats === [])) {
                    $v->errors()->add('applicable_category_ids', 'applies_to=categories requires at least one category.');
                }
                if ($appliesTo === 'products' && $prods !== null && (! is_array($prods) || $prods === [])) {
                    $v->errors()->add('applicable_product_ids', 'applies_to=products requires at least one product.');
                }
            },
        ];
    }
}
